<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\ContactRequest;
use App\Modules\Commercial\Services\ApplicationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página comercial pública. O cardápio vem do catálogo real de módulos (somente implementados e disponibilizados),
 * com os valores configurados pelo RootAdmin; nada é inventado.
 */
class LandingController extends Controller
{
    public function index(ApplicationCatalog $catalog): Response
    {
        return Inertia::render('welcome', [
            'applications' => $catalog->menu(),
            'trialDays' => (int) config('commercial.trial_days'),
            'contact' => $this->contactInfo(),
            'screenshots' => $this->screenshots(),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('legal/privacy', ['version' => config('commercial.terms_version'), 'contact' => $this->contactInfo()]);
    }

    public function terms(): Response
    {
        return Inertia::render('legal/terms', ['version' => config('commercial.terms_version'), 'trialDays' => (int) config('commercial.trial_days'), 'contact' => $this->contactInfo()]);
    }

    public function contact(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:2000'],
            'modules' => ['nullable', 'array', 'max:20'],
            'modules.*' => ['string', 'max:40'],
            // Campo invisível: preenchido somente por robôs.
            'website' => ['prohibited'],
        ], ['name.required' => 'Informe seu nome.', 'email.required' => 'Informe seu e-mail.', 'message.required' => 'Conte como podemos ajudar.']);
        unset($validated['website']);
        ContactRequest::create([...$validated, 'ip_address' => $request->ip()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mensagem recebida. Nossa equipe comercial vai responder pelo e-mail informado.']);

        return back();
    }

    /**
     * Capturas da interface real (geradas a partir do ambiente de demonstração), exibidas somente se existirem.
     *
     * @return list<array{src: string, alt: string}>
     */
    private function screenshots(): array
    {
        $images = [
            'painel' => 'Painel inicial do assinante com as aplicações contratadas',
            'vendas' => 'Tela de vendas com pedidos e indicadores',
            'pdv' => 'Frente de caixa (PDV) em uso',
            'mobile' => 'Painel do VetorFlex no celular',
        ];

        return array_values(array_filter(array_map(fn (string $file, string $alt): ?array => is_file(public_path("images/landing/{$file}.png")) ? ['src' => "/images/landing/{$file}.png", 'alt' => $alt] : null, array_keys($images), $images)));
    }

    /** @return array{email: string|null, whatsapp: string|null, company: string} */
    private function contactInfo(): array
    {
        return ['email' => config('commercial.contact.email'), 'whatsapp' => config('commercial.contact.whatsapp'), 'company' => (string) config('commercial.company.name')];
    }
}
