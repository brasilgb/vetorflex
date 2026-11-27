<?php

namespace App\Http\Controllers\Fiscal;

use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use App\Modules\Fiscal\Providers\Spedy\SpedyPayloadBuilder;
use App\Modules\Fiscal\Services\FiscalModule;
use App\Modules\Fiscal\Services\FiscalSettingsService;
use App\Modules\Fiscal\Support\FiscalPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuração fiscal da empresa: emitente, regime, tipos, séries, padrões tributários confirmados, cadastro no
 * provedor e certificado A1 (enviado direto ao provedor; arquivo e senha não são gravados nem registrados em log).
 */
class FiscalSettingsController extends FiscalController
{
    public function __construct(private FiscalSettingsService $settings, private FiscalModule $module) {}

    public function edit(Request $request, FiscalPresenter $presenter, FiscalProviderRegistry $providers): Response
    {
        $setting = $this->module->settings($this->company($request));
        $availability = $providers->availability($setting->provider ?? 'spedy');

        return Inertia::render('fiscal/settings', [
            'settings' => $presenter->settings($setting),
            'types' => $presenter->types(),
            'platform' => [
                'available' => $availability?->is_available === true && $availability->ownerKey() !== null,
                'environment' => $availability?->environment,
                'types' => $availability === null ? [] : ($availability->document_types ?? []),
                'provider' => $availability?->name,
            ],
            'readiness' => array_map(fn (DocumentType $type): array => ['type' => $type->value, 'label' => $type->shortLabel(), 'blockers' => $this->settings->blockers($setting, $type)], DocumentType::cases()),
            'taxRegimes' => [
                ['value' => 'simplesNacional', 'label' => 'Simples Nacional'],
                ['value' => 'simplesNacionalExcessoSublimite', 'label' => 'Simples Nacional — excesso de sublimite'],
                ['value' => 'simplesNacionalMEI', 'label' => 'MEI'],
                ['value' => 'regimeNormal', 'label' => 'Regime Normal (Lucro Presumido ou Real)'],
            ],
            'taxationTypes' => array_map(fn (string $value): array => ['value' => $value, 'label' => match ($value) {
                'taxationInMunicipality' => 'Tributado no município',
                'taxationOutsideMunicipality' => 'Tributado fora do município',
                'exemption' => 'Isento',
                'immune' => 'Imune',
                'suspendedByCourt' => 'Exigibilidade suspensa por decisão judicial',
                'suspendedByAdministrativeProcedure' => 'Exigibilidade suspensa por procedimento administrativo',
                'exportation' => 'Exportação',
                default => 'Não incidência',
            }], SpedyPayloadBuilder::TAXATION_TYPES),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:80'],
            'trade_name' => ['nullable', 'string', 'max:80'],
            'cnpj' => ['required', 'string', 'regex:/^\D*(\d\D*){14}$/'],
            'state_registration' => ['nullable', 'string', 'max:20'],
            'municipal_registration' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'array'],
            'address.postal_code' => ['required', 'string', 'max:10'],
            'address.street' => ['required', 'string', 'max:100'],
            'address.number' => ['required', 'string', 'max:10'],
            'address.complement' => ['nullable', 'string', 'max:150'],
            'address.district' => ['required', 'string', 'max:100'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.state' => ['required', 'string', 'size:2'],
            'city_code' => ['required', 'digits:7'],
            'tax_regime' => ['required', Rule::in(SpedyPayloadBuilder::TAX_REGIMES)],
            'environment' => ['required', Rule::in(['homologation', 'production'])],
            'enabled_types' => ['array'],
            'enabled_types.*' => [Rule::enum(DocumentType::class)],
            'series' => ['array'],
            'series.*' => ['nullable', 'string', 'regex:/^\d{1,3}$/'],
            'nfse' => ['array'],
            'nfse.service_code' => ['nullable', 'regex:/^\d{1,2}\.?\d{2}$/'],
            'nfse.iss_rate' => ['nullable', 'decimal:0,4', 'between:0,5'],
            'nfse.taxation_type' => ['nullable', Rule::in(SpedyPayloadBuilder::TAXATION_TYPES)],
            'nfse.issue_type' => ['nullable', Rule::in(['annfs', 'normal'])],
            'product_defaults' => ['array'],
            'product_defaults.unit' => ['nullable', 'string', 'max:6'],
            'product_defaults.tax_unit' => ['nullable', 'string', 'max:6'],
            'product_defaults.origin' => ['nullable', 'integer', 'between:0,8'],
            'product_defaults.cfop' => ['nullable', 'regex:/^5\d{3}$/'],
            'product_defaults.icms_situation' => ['nullable', 'regex:/^(\d{2}|\d{3})$/'],
            'product_defaults.pis_situation' => ['nullable', 'digits:2'],
            'product_defaults.cofins_situation' => ['nullable', 'digits:2'],
            'nfce_csc_id' => ['nullable', 'string', 'max:10'],
            'nfce_csc' => ['nullable', 'string', 'max:60'],
        ], ['cnpj.regex' => 'Informe um CNPJ com 14 dígitos.', 'city_code.digits' => 'O código IBGE do município tem 7 dígitos.', 'product_defaults.cfop.regex' => 'CFOP padrão: operação interna de saída (5xxx). O interestadual é ajustado na emissão.']);
        $this->settings->save($this->company($request), $request->user(), $validated);
        $this->toast('Configuração fiscal salva.');

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $this->settings->confirmTaxSettings($this->company($request), $request->user());
        $this->toast('Dados tributários confirmados.');

        return back();
    }

    public function register(Request $request): RedirectResponse
    {
        $this->settings->registerIssuer($this->company($request), $request->user());
        $this->toast('Empresa cadastrada como emissora no provedor fiscal.');

        return back();
    }

    public function certificate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'certificate' => ['required', 'file', 'max:100', 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:100'],
        ], ['certificate.required' => 'Selecione o arquivo do certificado A1 (.pfx).', 'password.required' => 'Informe a senha do certificado.']);
        $contents = (string) file_get_contents($validated['certificate']->getRealPath());
        $setting = $this->settings->uploadCertificate($this->company($request), $request->user(), $contents, (string) $validated['password']);
        $this->toast('Certificado aceito, válido até '.$setting->certificate_expires_at?->format('d/m/Y').'.');

        return back();
    }
}
