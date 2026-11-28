<?php

namespace App\Modules\Commercial\Services;

use App\Models\User;
use App\Modules\Commercial\Data\ProvisioningRequest;
use App\Modules\Commercial\Models\Signup;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cadastro público: cria o responsável (e-mail ainda não verificado) e guarda a escolha de aplicações e a empresa.
 * A empresa é provisionada depois da verificação do e-mail. O provisionamento é idempotente (chave do cadastro) e,
 * se falhar, é repetido no próximo acesso do responsável, sem duplicar nada.
 */
class SignupService
{
    public function __construct(private ApplicationCatalog $catalog, private CompanyProvisioning $provisioning) {}

    /**
     * @param  array{name: string, email: string, password: string, company_name: string, company_document?: string|null, modules: list<string>, idempotency_key: string}  $data
     */
    public function register(array $data, ?string $ip): User
    {
        $existing = Signup::query()->where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing?->user !== null) {
            return $existing->user;
        }

        $quote = $this->catalog->quote($data['modules']);

        try {
            $user = DB::transaction(function () use ($data, $ip, $quote): User {
                $user = User::create(['name' => trim($data['name']), 'email' => Str::lower(trim($data['email'])), 'password' => $data['password']]);
                Signup::create([
                    'idempotency_key' => $data['idempotency_key'],
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'company_name' => trim($data['company_name']),
                    'company_document' => preg_replace('/\D+/', '', (string) ($data['company_document'] ?? '')) ?: null,
                    'modules' => $quote['selected'],
                    'terms_version' => (string) config('commercial.terms_version'),
                    'accepted_terms_at' => now(),
                    'status' => Signup::PENDING,
                    'ip_address' => $ip,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $user = Signup::query()->where('idempotency_key', $data['idempotency_key'])->first()?->user;

            if ($user === null) {
                throw $exception;
            }

            return $user;
        }

        event(new Registered($user));

        return $user;
    }

    /** Provisiona os cadastros pendentes do usuário com e-mail verificado. Seguro para repetir. */
    public function provisionPendingFor(User $user): ?Company
    {
        if (! $user->hasVerifiedEmail()) {
            return null;
        }

        $company = null;

        foreach (Signup::query()->where('user_id', $user->id)->whereIn('status', [Signup::PENDING, Signup::FAILED])->orderBy('id')->get() as $signup) {
            $company = $this->provision($signup) ?? $company;
        }

        return $company;
    }

    public function provision(Signup $signup): ?Company
    {
        $user = $signup->user;

        if ($user === null) {
            return null;
        }

        try {
            $company = $this->provisioning->provision(new ProvisioningRequest(
                key: "signup:{$signup->idempotency_key}",
                owner: $user,
                actor: $user,
                companyName: $signup->company_name,
                modules: $signup->modules,
                document: $signup->company_document,
                trialDays: (int) config('commercial.trial_days', 14),
                origin: 'public',
            ));
        } catch (Throwable $exception) {
            // A seleção pode ter ficado indisponível ou o banco falhou: registra e tenta de novo no próximo acesso.
            $signup->update(['status' => Signup::FAILED, 'error' => Str::limit($exception->getMessage(), 490)]);
            Log::warning('Cadastro público: provisionamento não concluído', ['signup' => $signup->id, 'error' => $exception->getMessage()]);

            return null;
        }

        $signup->update(['status' => Signup::PROVISIONED, 'company_id' => $company->id, 'error' => null, 'provisioned_at' => now()]);

        return $company;
    }
}
