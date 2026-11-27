<?php

namespace App\Http\Controllers\Fiscal;

use App\Modules\Core\Services\AuditService;
use App\Modules\Fiscal\Enums\FiscalPermission;
use App\Modules\Fiscal\Services\FiscalModule;
use App\Modules\Products\Enums\ProductType;
use App\Modules\Products\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dados fiscais dos itens do catálogo compartilhado (NCM, CEST, origem, CFOP, CST/CSOSN, PIS/COFINS, unidade
 * tributável e item da lista de serviços). Só o formato é validado: nenhum código é sugerido ou completado.
 */
class FiscalItemController extends FiscalController
{
    private const FIELDS = ['ncm', 'cest', 'merchandise_origin', 'cfop', 'icms_situation', 'pis_situation', 'cofins_situation', 'service_code', 'service_city_code', 'tax_unit'];

    public function index(Request $request, FiscalModule $module): Response
    {
        $company = $this->company($request);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'missing' => ['nullable', 'boolean']]);
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('fiscal/items', [
            'items' => Product::query()->where('company_id', $company->id)->where('is_active', true)
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('internal_code', 'like', "%{$search}%")->orWhere('ncm', 'like', "%{$search}%")))
                ->when((bool) ($validated['missing'] ?? false), fn ($query) => $query->where(fn ($inner) => $inner
                    ->where(fn ($goods) => $goods->where('type', '!=', ProductType::Service->value)->where(fn ($empty) => $empty->whereNull('ncm')->orWhereNull('merchandise_origin')))
                    ->orWhere(fn ($services) => $services->where('type', ProductType::Service->value)->whereNull('service_code'))))
                ->orderBy('name')->paginate(30)->withQueryString()
                ->through(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'internal_code' => $product->internal_code, 'is_service' => $product->type === ProductType::Service, ...collect(self::FIELDS)->mapWithKeys(fn (string $field): array => [$field => $product->getAttribute($field)])->all()]),
            'filters' => ['search' => $search, 'missing' => (bool) ($validated['missing'] ?? false)],
            'canEdit' => $this->can($request, FiscalPermission::Edit),
            'hasProducts' => $module->isEnabled($company, 'products'),
        ]);
    }

    public function update(Request $request, Product $product, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'ncm' => ['nullable', 'digits:8'],
            'cest' => ['nullable', 'digits:7'],
            'merchandise_origin' => ['nullable', 'integer', 'between:0,8'],
            'cfop' => ['nullable', 'regex:/^[5-7]\d{3}$/'],
            'icms_situation' => ['nullable', 'regex:/^(\d{2}|\d{3})$/'],
            'pis_situation' => ['nullable', 'digits:2'],
            'cofins_situation' => ['nullable', 'digits:2'],
            'service_code' => ['nullable', 'regex:/^\d{1,2}\.?\d{2}$/'],
            'service_city_code' => ['nullable', 'string', 'max:7'],
            'tax_unit' => ['nullable', 'string', 'max:6'],
        ], ['ncm.digits' => 'O NCM tem 8 dígitos.', 'cest.digits' => 'O CEST tem 7 dígitos.', 'cfop.regex' => 'CFOP de saída: 4 dígitos iniciados por 5, 6 ou 7.', 'service_code.regex' => 'Item da lista de serviços (LC 116) no formato 01.07.']);
        $values = collect(self::FIELDS)->mapWithKeys(fn (string $field): array => [$field => isset($validated[$field]) && $validated[$field] !== '' ? (string) $validated[$field] : null])->all();
        $product->forceFill($values)->save();
        $audit->record('fiscal.item_fiscal_data_updated', $this->company($request), $request->user(), $product, ['fields' => array_keys($product->getChanges())]);
        $this->toast("Dados fiscais de \"{$product->name}\" salvos.");

        return back();
    }
}
