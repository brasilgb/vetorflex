<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RootAdmin\UpdatePlatformModuleRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\PlatformModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ModuleCatalogController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()?->is_root_admin, 403);

        return Inertia::render('root-admin/modules', [
            'modules' => PlatformModule::query()->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Disponibilidade comercial não ativa o módulo em nenhuma empresa.
     */
    public function update(UpdatePlatformModuleRequest $request, PlatformModule $module, AuditService $audit): RedirectResponse
    {
        $isAvailable = $request->boolean('commercially_available');

        if ($isAvailable && $module->development_status !== PlatformModule::IMPLEMENTED) {
            throw ValidationException::withMessages(['commercially_available' => 'Somente módulos implementados podem ser disponibilizados.']);
        }

        $module->update(['commercially_available' => $isAvailable]);
        $audit->record('module.availability_changed', null, $request->user(), $module, ['commercially_available' => $isAvailable]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Disponibilidade comercial atualizada.']);

        return to_route('root-admin.modules.index');
    }
}
