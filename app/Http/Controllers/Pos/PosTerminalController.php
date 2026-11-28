<?php

namespace App\Http\Controllers\Pos;

use App\Models\User;
use App\Modules\Pos\Models\PosTerminal;
use App\Modules\Pos\Services\PosTerminalService;
use App\Modules\Pos\Support\PosPresenter;
use App\Modules\Tenancy\Models\CompanyMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro dos pontos de atendimento e dos operadores autorizados.
 */
class PosTerminalController extends PosController
{
    public function __construct(private PosTerminalService $terminals, private PosPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        return Inertia::render('pos/terminals', [
            'terminals' => PosTerminal::query()->where('company_id', $company->id)->with(['operators', 'openSession'])->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (PosTerminal $terminal): array => $this->presenter->terminal($terminal))->values()->all(),
            'members' => User::query()->whereIn('id', CompanyMembership::query()->where('company_id', $company->id)->where('is_active', true)->select('user_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $terminal = $this->terminals->save($this->company($request), $request->user(), new PosTerminal, $this->validated($request, null));
        $this->toast("Terminal {$terminal->name} cadastrado.");

        return back();
    }

    public function update(Request $request, PosTerminal $terminal): RedirectResponse
    {
        $this->terminals->save($this->company($request), $request->user(), $terminal, $this->validated($request, $terminal));
        $this->toast("Terminal {$terminal->name} atualizado.");

        return back();
    }

    /** @return array{name: string, code: string, location?: string|null, is_active?: bool, receipt_width?: int|null, operators?: list<int>} */
    private function validated(Request $request, ?PosTerminal $terminal): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('pos_terminals', 'code')->where('company_id', $this->company($request)->id)->ignore($terminal?->id)],
            'location' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'receipt_width' => ['nullable', 'in:58,80'],
            'operators' => ['nullable', 'array', 'max:100'],
            'operators.*' => ['integer'],
        ], ['name.required' => 'Informe o nome do terminal.', 'code.required' => 'Informe a identificação do terminal.', 'code.unique' => 'Já existe um terminal com esta identificação.', 'code.alpha_dash' => 'Use letras, números, hífen ou sublinhado na identificação.']);
    }
}
