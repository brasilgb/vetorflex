<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\ModuleManager\Models\PlatformModule;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão geral da plataforma, somente com contagens reais do banco. Não há indicadores de faturamento ou uso
 * porque esses dados ainda não existem na plataforma.
 */
class PlatformOverviewController extends Controller
{
    public function __invoke(): Response
    {
        $activeModules = DB::table('company_modules')->whereNull('deactivated_at')
            ->selectRaw('platform_module_id, count(*) as total')->groupBy('platform_module_id')->pluck('total', 'platform_module_id');

        return Inertia::render('root-admin/overview', [
            'companies' => Company::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'users' => [
                'total' => User::query()->count(),
                'root_admins' => User::query()->where('is_root_admin', true)->count(),
                'without_company' => User::query()->where('is_root_admin', false)->whereDoesntHave('companies')->count(),
                'verified' => User::query()->whereNotNull('email_verified_at')->count(),
            ],
            'modules' => PlatformModule::query()->orderBy('sort_order')->get()->map(fn (PlatformModule $module): array => [
                'id' => $module->id,
                'name' => $module->name,
                'development_status' => $module->development_status,
                'commercially_available' => (bool) $module->commercially_available,
                'active_companies' => (int) ($activeModules[$module->id] ?? 0),
            ])->values()->all(),
            'recent' => AuditLog::query()->with(['user:id,name', 'company:id,name'])->latest('occurred_at')->latest('id')->limit(10)->get()
                ->map(fn (AuditLog $log): array => AuditLogController::present($log))->values()->all(),
        ]);
    }
}
