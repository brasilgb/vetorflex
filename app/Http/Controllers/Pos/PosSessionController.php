<?php

namespace App\Http\Controllers\Pos;

use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Pos\Models\PosCashMovement;
use App\Modules\Pos\Models\PosRefund;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosSessionService;
use App\Modules\Pos\Support\PosPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Histórico, resumo e fechamento das sessões de caixa. Fechada, a sessão só aceita correções por ajuste autorizado.
 */
class PosSessionController extends PosController
{
    public function __construct(private PosSessionService $sessions, private PosPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $filters = $request->validate(['terminal' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,closed'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('pos/sessions/index', [
            'sessions' => PosSession::query()->where('company_id', $company->id)->with('terminal')
                ->when($filters['terminal'] ?? null, fn (Builder $query, int $terminal) => $query->where('pos_terminal_id', $terminal))
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('opened_at', '>=', now($company->timezone)->parse($from, $company->timezone)->startOfDay()->utc()))
                ->when($filters['until'] ?? null, fn (Builder $query, string $until) => $query->where('opened_at', '<=', now($company->timezone)->parse($until, $company->timezone)->endOfDay()->utc()))
                ->latest('opened_at')->paginate(30)->withQueryString()
                ->through(fn (PosSession $session): array => $this->presenter->session($session)),
            'filters' => ['terminal' => isset($filters['terminal']) ? (int) $filters['terminal'] : null, 'status' => $filters['status'] ?? null, 'from' => $filters['from'] ?? null, 'until' => $filters['until'] ?? null],
            'terminals' => PosTerminal::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])->map(fn (PosTerminal $terminal): array => ['id' => $terminal->id, 'name' => $terminal->name])->values()->all(),
        ]);
    }

    public function show(Request $request, PosSession $session): Response
    {
        $user = $request->user();
        abort_unless($this->can($request, PosPermission::ViewSessions) || $session->user_id === $user->id, 403);
        $summary = $this->sessions->summary($session);
        $hidesExpected = $session->isOpen() && $session->is_blind && ! $this->can($request, PosPermission::Authorize);

        if ($hidesExpected) {
            unset($summary['expected_cash']);
        }

        return Inertia::render('pos/sessions/show', [
            'session' => $this->presenter->session($session->load('terminal')),
            'summary' => $summary,
            'hidesExpected' => $hidesExpected,
            'movements' => PosCashMovement::query()->where('pos_session_id', $session->id)->orderBy('occurred_at')->orderBy('id')->get()
                ->map(fn (PosCashMovement $movement): array => $this->presenter->movement($movement))->values()->all(),
            'sales' => PosSale::query()->where('pos_session_id', $session->id)->with(['order', 'terminal', 'session'])->latest('id')->limit(300)->get()
                ->map(fn (PosSale $sale): array => $this->presenter->sale($sale))->values()->all(),
            'refunds' => PosRefund::query()->where('pos_session_id', $session->id)->with('saleReturn')->orderBy('id')->get()
                ->map(fn (PosRefund $refund): array => $this->presenter->refund($refund))->values()->all(),
            'canClose' => $session->isOpen() && $this->can($request, PosPermission::CloseSession),
            'canCorrect' => ! $session->isOpen() && $this->can($request, PosPermission::Authorize),
            'isOwnSession' => $session->user_id === $user->id,
        ]);
    }

    public function close(Request $request, PosSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ], ['counted_cash.required' => 'Informe o dinheiro contado na gaveta.']);
        $session = $this->sessions->close($session, $request->user(), (string) $validated['counted_cash'], $validated['closing_notes'] ?? null, $this->credentials($request));
        $this->toast('Caixa fechado.');

        return to_route('pos.sessions.show', $session);
    }
}
