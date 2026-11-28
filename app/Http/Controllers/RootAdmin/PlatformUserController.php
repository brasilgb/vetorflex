<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Consulta de usuários da plataforma e de seus vínculos com empresas. Somente leitura: papéis e permissões são
 * geridos pela própria empresa, e o RootAdmin não ganha acesso empresarial por aqui.
 */
class PlatformUserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['term' => ['nullable', 'string', 'max:100'], 'company' => ['nullable', 'integer']]);

        $users = User::query()
            ->when($filters['term'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $inner) => $inner->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->when($filters['company'] ?? null, fn (Builder $query, int $company) => $query->whereHas('companies', fn (Builder $companies) => $companies->whereKey($company)))
            ->orderBy('name')->paginate(30)->withQueryString();
        $memberships = DB::table('company_user')->join('companies', 'companies.id', '=', 'company_user.company_id')
            ->whereIn('company_user.user_id', $users->getCollection()->modelKeys())->orderBy('companies.name')
            ->get(['company_user.user_id', 'companies.id', 'companies.name', 'company_user.role', 'company_user.is_active'])
            ->groupBy('user_id');

        return Inertia::render('root-admin/users', [
            'users' => $users->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_root_admin' => $user->is_root_admin,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'companies' => ($memberships[$user->id] ?? collect())->map(fn (stdClass $membership): array => [
                    'id' => (int) $membership->id,
                    'name' => (string) $membership->name,
                    'role' => (string) $membership->role,
                    'is_active' => (bool) $membership->is_active,
                ])->values()->all(),
            ]),
            'filters' => ['term' => $filters['term'] ?? null, 'company' => isset($filters['company']) ? (int) $filters['company'] : null],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
