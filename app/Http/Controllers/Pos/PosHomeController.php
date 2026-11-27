<?php

namespace App\Http\Controllers\Pos;

use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Enums\PosSaleStatus;
use App\Modules\Pos\Enums\PosSessionStatus;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Support\PosPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entrada do PDV: terminais disponíveis, caixas abertos e abertura de caixa.
 */
class PosHomeController extends PosController
{
    public function __construct(private PosSessionService $sessions, private PosPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $user = $request->user();
        $terminals = PosTerminal::query()->where('company_id', $company->id)->where('is_active', true)->with(['operators', 'openSession'])->orderBy('name')->get();

        return Inertia::render('pos/index', [
            'terminals' => $terminals->map(fn (PosTerminal $terminal): array => [
                ...$this->presenter->terminal($terminal),
                'may_open' => $this->sessions->mayOperate($company, $terminal, $user),
            ])->values()->all(),
            'mySessions' => PosSession::query()->where('company_id', $company->id)->where('status', PosSessionStatus::Open)->where('user_id', $user->id)->with('terminal')->get()
                ->map(fn (PosSession $session): array => $this->presenter->session($session))->values()->all(),
            'suspendedCount' => PosSale::query()->where('company_id', $company->id)->where('status', PosSaleStatus::Suspended)->count(),
            'canOpen' => $this->can($request, PosPermission::OpenSession),
            'canManageTerminals' => $this->can($request, PosPermission::ManageTerminals),
        ]);
    }

    public function open(Request $request, PosTerminal $terminal): RedirectResponse
    {
        $validated = $request->validate([
            'opening_amount' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'opening_notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ], ['opening_amount.required' => 'Informe o valor inicial em dinheiro (pode ser zero).']);
        $session = $this->sessions->open($this->company($request), $request->user(), $terminal, (string) $validated['opening_amount'], $validated['opening_notes'] ?? null, $validated['idempotency_key'] ?? (string) Str::uuid());
        $this->toast("Caixa aberto em {$terminal->name}.");

        return to_route('pos.register', $session);
    }
}
