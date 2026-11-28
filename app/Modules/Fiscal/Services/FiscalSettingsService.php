<?php

namespace App\Modules\Fiscal\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Fiscal\Enums\DocumentType;
use App\Modules\Fiscal\Models\FiscalSetting;
use App\Modules\Fiscal\Providers\FiscalProvider;
use App\Modules\Fiscal\Providers\FiscalProviderException;
use App\Modules\Fiscal\Providers\FiscalProviderRegistry;
use App\Modules\Fiscal\Providers\FiscalValidationException;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configuração fiscal da empresa: dados do emitente, regime, tipos habilitados, séries, padrões confirmados pelo
 * responsável, cadastro no provedor e certificado. Alterar dados tributários exige nova confirmação. Nenhum código
 * fiscal é preenchido por aproximação: o que não for informado aparece como pendência.
 */
class FiscalSettingsService
{
    /** Campos cuja alteração invalida a confirmação dos padrões tributários. */
    private const TAX_FIELDS = ['tax_regime', 'nfse', 'product_defaults', 'city_code'];

    public function __construct(private FiscalModule $module, private FiscalProviderRegistry $providers, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function save(Company $company, User $user, array $data): FiscalSetting
    {
        $setting = $this->module->settings($company);
        $setting->fill([
            ...collect($data)->only(['legal_name', 'trade_name', 'state_registration', 'municipal_registration', 'email', 'phone', 'city_code', 'tax_regime', 'environment', 'nfce_csc_id'])->all(),
            'cnpj' => preg_replace('/\D+/', '', (string) ($data['cnpj'] ?? '')) ?: null,
            'address' => $data['address'] ?? $setting->address,
            'enabled_types' => array_values(array_intersect(array_map(fn (DocumentType $type): string => $type->value, DocumentType::cases()), (array) ($data['enabled_types'] ?? []))),
            'series' => $data['series'] ?? $setting->series,
            'nfse' => $data['nfse'] ?? $setting->nfse,
            'product_defaults' => $data['product_defaults'] ?? $setting->product_defaults,
            'provider' => $data['provider'] ?? $setting->provider ?? 'spedy',
        ]);

        // CSC só é substituído quando informado; nunca é devolvido às telas.
        if (filled($data['nfce_csc'] ?? null)) {
            $setting->nfce_csc = (string) $data['nfce_csc'];
        }

        if ($setting->isDirty(self::TAX_FIELDS) && $setting->tax_settings_confirmed_at !== null) {
            $setting->forceFill(['tax_settings_confirmed_at' => null, 'tax_settings_confirmed_by' => null]);
        }

        $changed = array_keys($setting->getDirty());
        $setting->save();
        $this->audit->record('fiscal.settings_updated', $company, $user, $setting, ['fields' => array_values(array_diff($changed, ['nfce_csc', 'updated_at']))]);

        return $setting;
    }

    /** O responsável confirma que regime, padrões e códigos informados foram revisados (preferencialmente pelo contador). */
    public function confirmTaxSettings(Company $company, User $user): FiscalSetting
    {
        $setting = $this->module->settings($company);

        if (blank($setting->tax_regime)) {
            throw ValidationException::withMessages(['tax_regime' => 'Informe o regime tributário antes de confirmar.']);
        }

        $setting->forceFill(['tax_settings_confirmed_at' => now(), 'tax_settings_confirmed_by' => $user->name])->save();
        $this->audit->record('fiscal.tax_settings_confirmed', $company, $user, $setting);

        return $setting;
    }

    public function registerIssuer(Company $company, User $user): FiscalSetting
    {
        $setting = $this->module->settings($company);
        $provider = $this->provider($setting);

        try {
            $setting = $provider->registerIssuer($setting);
            $setting->forceFill(['registration_status' => FiscalSetting::REGISTRATION_REGISTERED, 'registration_error' => null, 'registered_at' => now()])->save();
        } catch (FiscalValidationException $exception) {
            throw $exception;
        } catch (FiscalProviderException $exception) {
            $setting->forceFill(['registration_status' => FiscalSetting::REGISTRATION_ERROR, 'registration_error' => Str::limit($exception->getMessage(), 990)])->save();
            $this->audit->record('fiscal.issuer_registration_failed', $company, $user, $setting, ['status' => $exception->status]);

            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        $this->audit->record('fiscal.issuer_registered', $company, $user, $setting, ['provider' => $provider->identifier()]);

        return $setting;
    }

    /** O certificado A1 vai direto ao provedor; aqui ficam só titular e validade. Arquivo e senha nunca são gravados. */
    public function uploadCertificate(Company $company, User $user, string $contents, string $password): FiscalSetting
    {
        $setting = $this->module->settings($company);
        $provider = $this->provider($setting);

        if (! $provider->capabilities()->certificateUpload) {
            throw ValidationException::withMessages(['certificate' => "{$provider->name()} não recebe certificado pela integração."]);
        }

        try {
            $certificate = $provider->uploadCertificate($setting, $contents, $password);
        } catch (FiscalProviderException $exception) {
            $this->audit->record('fiscal.certificate_rejected', $company, $user, $setting, ['status' => $exception->status]);

            throw ValidationException::withMessages(['certificate' => $exception->getMessage()]);
        }

        $setting->forceFill(['certificate_subject' => $certificate['subject'], 'certificate_expires_at' => $certificate['expires_at']])->save();
        $this->audit->record('fiscal.certificate_uploaded', $company, $user, $setting, ['expires_at' => $certificate['expires_at']->toIso8601String()]);

        return $setting;
    }

    /**
     * Pendências que impedem a emissão do tipo informado, em linguagem de negócio. Lista vazia: pronto para emitir.
     *
     * @return list<string>
     */
    public function blockers(FiscalSetting $setting, DocumentType $type): array
    {
        $identifier = $setting->provider ?? 'spedy';
        $availability = $this->providers->availability($identifier);
        $problems = [];

        if ($availability === null || ! $availability->is_available || $availability->ownerKey() === null) {
            return ['A emissão fiscal ainda não está disponível na plataforma. Contate o suporte.'];
        }

        if (! $this->providers->allowsType($identifier, $type)) {
            return ["{$type->shortLabel()} não está liberada na plataforma."];
        }

        if (! $setting->enables($type)) {
            $problems[] = "Habilite a {$type->shortLabel()} nas configurações fiscais.";
        }

        if ($setting->isProduction() && $availability->environment !== 'production') {
            $problems[] = 'A plataforma está em ambiente de testes (sandbox): emita em homologação.';
        }

        if ($setting->isProduction() && $setting->production_released_at === null) {
            $problems[] = 'A emissão em produção ainda não foi liberada pela plataforma para esta empresa.';
        }

        if (! $setting->isRegistered()) {
            $problems[] = 'Cadastre a empresa como emissora no provedor fiscal.';
        }

        if (! $setting->hasValidCertificate()) {
            $problems[] = $setting->certificate_expires_at === null ? 'Envie o certificado digital A1 da empresa.' : 'O certificado digital está vencido. Envie um certificado vigente.';
        }

        if ($setting->tax_settings_confirmed_at === null) {
            $problems[] = 'Confirme os dados tributários (regime e padrões) nas configurações fiscais.';
        }

        if ($type === DocumentType::Nfse && blank($setting->nfse['taxation_type'] ?? null)) {
            $problems[] = 'Informe o tipo de tributação da NFS-e.';
        }

        if ($type === DocumentType::Nfce && (blank($setting->nfce_csc_id) || blank($setting->nfce_csc))) {
            $problems[] = 'Informe o ID e o CSC da NFC-e (fornecidos pela SEFAZ).';
        }

        return $problems;
    }

    private function provider(FiscalSetting $setting): FiscalProvider
    {
        $identifier = $setting->provider ?? 'spedy';

        if (! $this->providers->isAvailable($identifier)) {
            throw ValidationException::withMessages(['provider' => 'A emissão fiscal ainda não está disponível na plataforma. Contate o suporte.']);
        }

        return $this->providers->get($identifier);
    }
}
