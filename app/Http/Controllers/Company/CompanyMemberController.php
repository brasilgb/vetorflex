<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use App\Modules\Tenancy\Models\CompanyMembership;
use App\Modules\Tenancy\Services\MemberPermissionService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuários da empresa e suas permissões individuais, agrupadas pelos módulos contratados.
 */
class CompanyMemberController extends Controller
{
    use InteractsWithCompany;

    private const HISTORY_SIZE = 30;

    public function __construct(private TenantContext $context, private MemberPermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $actor = $this->actor($request);
        $grantable = $this->permissions->grantable($this->context->enabledModules($request), $this->context->permissions($request));

        return Inertia::render('company/members', [
            'members' => CompanyMembership::query()->where('company_id', $company->id)->with('user')->get()
                ->sortBy(fn (CompanyMembership $membership): string => $membership->user->name)
                ->map(fn (CompanyMembership $membership): array => [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => ['value' => $membership->role->value, 'label' => $membership->role->label()],
                    'is_active' => $membership->is_active,
                    'is_self' => $membership->user_id === $request->user()->id,
                    'permissions' => $membership->isOwner() ? 'all' : ($membership->permissions ?? []),
                    'can_manage' => $this->permissions->canManage($actor, $request->user(), $membership, $membership->user),
                ])->values(),
            'modules' => $this->context->enabledModules($request)->map(fn (PlatformModule $module): array => [
                'identifier' => $module->identifier,
                'name' => $module->name,
                'permissions' => $module->permissions ?? [],
            ])->values(),
            'grantable' => $grantable,
            'history' => AuditLog::query()->where('company_id', $company->id)->where('event', 'company.permissions_changed')
                ->latest('occurred_at')->latest('id')->limit(self::HISTORY_SIZE)->get()
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'user_name' => $log->context['user_name'] ?? null,
                    'actor_name' => User::query()->whereKey($log->user_id)->value('name'),
                    'granted' => $log->context['granted'] ?? [],
                    'revoked' => $log->context['revoked'] ?? [],
                    'occurred_at' => $log->occurred_at->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['present', 'array', 'list', 'max:500'],
            'permissions.*' => ['string', 'max:100', 'distinct'],
        ]);
        $grantable = $this->permissions->grantable($this->context->enabledModules($request), $this->context->permissions($request));
        $this->permissions->update($this->company($request), $request->user(), $this->actor($request), $member, $validated['permissions'], $grantable);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Permissões de {$member->name} atualizadas."]);

        return back();
    }

    private function actor(Request $request): CompanyMembership
    {
        $actor = $this->context->membership($request);
        abort_unless($actor !== null && $this->permissions->canManageTeam($actor), 403);

        return $actor;
    }
}
