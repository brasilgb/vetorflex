<?php

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Support\CsvFormat;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Support\BrazilianDocument;
use App\Modules\Purchases\Models\SupplierImport;
use App\Modules\Purchases\Models\SupplierProfile;
use App\Modules\Purchases\Support\SupplierCsv;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importação de fornecedores em duas etapas. Pessoas já cadastradas com o mesmo documento só recebem o papel
 * fornecedor quando o vínculo é confirmado; seus dados não são alterados. Fornecedores existentes são ignorados.
 *
 * @phpstan-type EvaluatedRow array{line: int, name: string, status: 'new'|'link'|'existing'|'invalid', attributes: array<string, mixed>, party_name: string|null, errors: list<string>}
 */
class SupplierImportService
{
    public function __construct(private SupplierCsv $csv, private SupplierService $suppliers, private AuditService $audit) {}

    public function preview(Company $company, User $user, UploadedFile $file): SupplierImport
    {
        return SupplierImport::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'file_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'status' => SupplierImport::PREVIEW,
            'rows' => $this->csv->read($file),
        ]);
    }

    /** @return list<EvaluatedRow> */
    public function rows(SupplierImport $import, Company $company): array
    {
        $documents = [];
        $codes = [];
        $evaluated = [];

        foreach ($import->rows ?? [] as $row) {
            $data = $row['data'];
            $errors = $row['errors'];
            $document = BrazilianDocument::normalize($data['documento'] ?? null);
            $type = match (Str::lower(Str::ascii(trim($data['tipo'] ?? '')))) {
                'juridica', 'pj', 'company', 'empresa' => 'company',
                'fisica', 'pf', 'individual', 'pessoa' => 'individual',
                '' => $document !== null && strlen($document) === 14 ? 'company' : 'individual',
                default => null,
            };
            $attributes = [
                'type' => $type,
                'name' => trim($data['nome'] ?? ''),
                'trade_name' => ($data['nome_fantasia'] ?? '') ?: null,
                'document' => $document,
                'state_registration' => ($data['inscricao_estadual'] ?? '') ?: null,
                'municipal_registration' => ($data['inscricao_municipal'] ?? '') ?: null,
                'email' => ($data['email'] ?? '') ?: null,
                'phone' => ($data['telefone'] ?? '') ?: null,
                'mobile' => ($data['celular'] ?? '') ?: null,
                'whatsapp' => ($data['whatsapp'] ?? '') ?: null,
                'internal_code' => ($data['codigo_interno'] ?? '') ?: null,
                'lead_time_days' => ($data['prazo_entrega_dias'] ?? '') === '' ? null : $data['prazo_entrega_dias'],
                'payment_terms' => ($data['condicao_pagamento'] ?? '') ?: null,
                'minimum_order' => ($data['pedido_minimo'] ?? '') === '' ? null : CsvFormat::parseDecimal($data['pedido_minimo']),
                'notes' => ($data['observacoes'] ?? '') ?: null,
            ];

            $errors = [...$errors, ...$this->errors($attributes)];

            if ($document !== null && in_array($document, $documents, true)) {
                $errors[] = 'Documento repetido no arquivo.';
            }

            if ($attributes['internal_code'] !== null && (in_array($attributes['internal_code'], $codes, true)
                || SupplierProfile::query()->where('company_id', $company->id)->where('internal_code', $attributes['internal_code'])->exists())) {
                $errors[] = 'Código interno já utilizado.';
            }

            $party = $document === null ? null : Party::query()->where('company_id', $company->id)->where('document', $document)->with('supplierProfile')->first();
            $documents[] = $document;
            $codes[] = $attributes['internal_code'];
            $evaluated[] = [
                'line' => $row['line'],
                'name' => $attributes['name'],
                'status' => match (true) {
                    $errors !== [] => 'invalid',
                    $party?->supplierProfile !== null => 'existing',
                    $party !== null => 'link',
                    default => 'new',
                },
                'attributes' => $attributes,
                'party_name' => $party?->name,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return $evaluated;
    }

    public function confirm(SupplierImport $import, Company $company, User $user, bool $linkExisting): SupplierImport
    {
        return DB::transaction(function () use ($import, $company, $user, $linkExisting): SupplierImport {
            $import = SupplierImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($import->status === SupplierImport::COMPLETED) {
                return $import;
            }

            $report = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

            foreach ($this->rows($import, $company) as $row) {
                [$outcome, $message] = match ($row['status']) {
                    'invalid' => ['failed', implode(' ', $row['errors'])],
                    'existing' => ['skipped', "Já é fornecedor: {$row['party_name']}."],
                    'link' => $linkExisting ? ['linked', null] : ['skipped', "Pessoa existente ({$row['party_name']}); vínculo não confirmado."],
                    'new' => ['created', null],
                };

                if ($outcome === 'created' || $outcome === 'linked') {
                    $this->suppliers->create($company, $user, [...$row['attributes'], 'link_existing' => $outcome === 'linked']);
                }

                $report[$outcome]++;

                if ($message !== null) {
                    $report['errors'][] = ['line' => $row['line'], 'name' => $row['name'], 'messages' => [$message]];
                }
            }

            $import->update(['status' => SupplierImport::COMPLETED, 'rows' => null, 'report' => $report, 'completed_at' => now()]);
            $this->audit->record('supplier.imported', $company, $user, $import, Arr::except($report, 'errors'));

            return $import;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function errors(array $attributes): array
    {
        $validator = Validator::make($attributes, [
            'type' => ['required'],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'internal_code' => ['nullable', 'string', 'max:60'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:999'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'minimum_order' => ['nullable', 'decimal:0,2', 'min:0'],
        ], [
            'type.required' => 'Tipo inválido: use "fisica" ou "juridica".',
            'name.required' => 'O nome é obrigatório.',
            'email.email' => 'E-mail inválido.',
            'lead_time_days.integer' => 'Prazo de entrega deve ser um número inteiro de dias.',
            'minimum_order.decimal' => 'Pedido mínimo inválido.',
        ]);
        $errors = array_values($validator->errors()->all());
        $document = $attributes['document'];

        if ($document !== null && $attributes['type'] !== null
            && ! ($attributes['type'] === 'company' ? BrazilianDocument::isCnpj($document) : BrazilianDocument::isCpf($document))) {
            $errors[] = $attributes['type'] === 'company' ? 'CNPJ inválido.' : 'CPF inválido.';
        }

        return $errors;
    }
}
