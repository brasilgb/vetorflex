<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenantContext = app(TenantContext::class);
        $activeCompany = $tenantContext->activeCompany($request);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'tenant' => [
                'activeCompany' => $activeCompany?->only(['id', 'name', 'timezone']),
                'companies' => $request->user() ? $tenantContext->companiesFor($request->user())->map->only(['id', 'name'])->values() : [],
                'modules' => $activeCompany ? $tenantContext->enabledModules($request)->map->only(['identifier', 'name'])->values() : [],
                'permissions' => $tenantContext->permissions($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
