<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administradores da plataforma. Novos administradores são cadastrados pelo comando `rootadmin:create`; aqui o
 * acesso administrativo pode ser revogado, nunca do último administrador ativo.
 */
class AdministratorController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('root-admin/administrators', [
            'administrators' => User::query()->where('is_root_admin', true)->orderBy('name')->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'is_current' => $user->id === $request->user()->id,
                ])->values()->all(),
        ]);
    }

    public function destroy(Request $request, User $administrator, AuditService $audit): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:400']], ['reason.required' => 'Informe o motivo.']);

        DB::transaction(function () use ($administrator): void {
            $remaining = User::query()->where('is_root_admin', true)->lockForUpdate()->pluck('id');

            if (! $remaining->contains($administrator->id)) {
                throw ValidationException::withMessages(['administrator' => 'Este usuário não é administrador da plataforma.']);
            }

            if ($remaining->count() <= 1) {
                throw ValidationException::withMessages(['administrator' => 'Não é possível revogar o último administrador ativo da plataforma.']);
            }

            $administrator->forceFill(['is_root_admin' => false])->save();
        });

        $audit->record('root_admin.revoked', null, $request->user(), $administrator, ['email' => $administrator->email, 'reason' => $request->input('reason')]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Acesso administrativo de {$administrator->name} revogado."]);

        return $administrator->id === $request->user()->id ? to_route('dashboard') : to_route('root-admin.administrators.index');
    }
}
