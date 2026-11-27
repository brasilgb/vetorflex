<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Models\FiscalDocument;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Providers\FiscalProviderException;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * XML e PDF dos documentos fiscais em disco privado (`fiscal`), segregados por empresa: `{empresa}/{tipo}/{ano/mês}/
 * {documento}-{situação}.{formato}`. Cada arquivo guarda o SHA-256 para conferência de integridade. Os arquivos só
 * saem por rota autenticada, com módulo, permissão e empresa conferidos; nunca há URL pública.
 */
class FiscalFiles
{
    public function __construct(private FiscalProviderRegistry $providers) {}

    public function store(FiscalDocument $document): FiscalDocument
    {
        $setting = FiscalSetting::query()->where('company_id', $document->company_id)->firstOrFail();
        $provider = $this->providers->get((string) $document->provider);
        $capabilities = $provider->capabilities();
        $updates = [];

        foreach (['xml' => $capabilities->xml, 'pdf' => $capabilities->pdf] as $format => $supported) {
            if (! $supported) {
                continue;
            }

            $contents = $provider->download($setting, $document, $format);

            if ($format === 'xml' && ! str_contains(substr(ltrim($contents), 0, 200), '<')) {
                throw new FiscalProviderException('O provedor retornou um XML inválido.');
            }

            $path = sprintf('%d/%s/%s/%d-%s.%s', $document->company_id, $document->type->value, ($document->issued_at ?? now())->format('Y/m'), $document->id, $document->status->value, $format);
            $this->disk()->put($path, $contents);
            $updates["{$format}_path"] = $path;
            $updates["{$format}_sha256"] = hash('sha256', $contents);
        }

        $document->update($updates);

        return $document;
    }

    /**
     * Conteúdo conferido pelo SHA-256. Arquivo ausente ou adulterado: nova cópia é obtida do provedor.
     *
     * @return array{contents: string, filename: string, mime: string}
     */
    public function read(FiscalDocument $document, string $format): array
    {
        if (! $document->hasFiles()) {
            throw ValidationException::withMessages(['document' => 'Arquivos disponíveis somente para documentos autorizados ou cancelados.']);
        }

        $path = $document->getAttribute("{$format}_path");
        $hash = $document->getAttribute("{$format}_sha256");
        $contents = is_string($path) && $this->disk()->exists($path) ? (string) $this->disk()->get($path) : null;

        if ($contents === null || ! hash_equals((string) $hash, hash('sha256', $contents))) {
            try {
                $document = $this->store($document);
            } catch (FiscalProviderException $exception) {
                throw ValidationException::withMessages(['document' => $exception->getMessage()]);
            }

            $contents = (string) $this->disk()->get((string) $document->getAttribute("{$format}_path"));
        }

        $name = sprintf('%s-%s-%s.%s', $document->type->value, $document->number ?? "interno-{$document->local_number}", $document->status->value, $format);

        return ['contents' => $contents, 'filename' => $name, 'mime' => $format === 'xml' ? 'application/xml' : 'application/pdf'];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('fiscal.disk', 'fiscal'));
    }
}
