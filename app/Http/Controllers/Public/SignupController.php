<?php

namespace App\Http\Controllers\Public;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Signup;
use App\Modules\Commercial\Services\ApplicationCatalog;
use App\Modules\Commercial\Services\SignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jornada de cadastro: aplicações, revisão dos valores, responsável, empresa e aceite dos termos. A seleção e os
 * valores são recalculados no servidor; a empresa é provisionada após a verificação do e-mail.
 */
class SignupController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(Request $request, ApplicationCatalog $catalog): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return to_route('dashboard');
        }

        $requested = array_values(array_filter(explode(',', (string) $request->query('apps', ''))));

        return Inertia::render('auth/register', [
            'applications' => $catalog->menu(),
            'preselected' => $requested,
            'trialDays' => (int) config('commercial.trial_days'),
            'termsVersion' => config('commercial.terms_version'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /** Revisão da seleção: dependências incluídas e total, sempre calculados aqui. */
    public function quote(Request $request, ApplicationCatalog $catalog): JsonResponse
    {
        $validated = $request->validate(['modules' => ['required', 'array', 'min:1', 'max:30'], 'modules.*' => ['string', 'max:40']], ['modules.required' => 'Escolha ao menos uma aplicação.']);

        return response()->json($catalog->quote($validated['modules']));
    }

    public function store(Request $request, SignupService $signups): RedirectResponse
    {
        // Reenvio do mesmo cadastro (duplo clique, rede instável): só retoma a sessão com a mesma senha.
        $repeated = Signup::query()->where('idempotency_key', (string) $request->input('idempotency_key'))->first()?->user;

        if ($repeated !== null && Hash::check((string) $request->input('password'), $repeated->password)) {
            Auth::login($repeated);
            $request->session()->regenerate();

            return to_route('verification.notice');
        }

        $validated = $request->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'company_document' => ['nullable', 'string', 'regex:/^\D*(\d\D*){11}$|^\D*(\d\D*){14}$/'],
            'modules' => ['required', 'array', 'min:1', 'max:30'],
            'modules.*' => ['string', 'max:40'],
            'accept_terms' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'company_name.required' => 'Informe o nome da empresa.',
            'company_document.regex' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos), ou deixe em branco.',
            'modules.required' => 'Escolha ao menos uma aplicação.',
            'accept_terms.accepted' => 'É preciso aceitar os termos de uso e a política de privacidade.',
            'email.unique' => 'Já existe uma conta com este e-mail. Entre para continuar.',
        ]);
        $user = $signups->register($validated, $request->ip());
        Auth::login($user);
        $request->session()->regenerate();

        return to_route('verification.notice');
    }
}
