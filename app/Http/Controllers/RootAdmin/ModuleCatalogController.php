<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RootAdmin\UpdatePlatformModuleRequest;
use App\Modules\Core\Services\AuditService;
use App\Modules\ModuleManager\Models\PlatformModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /** Dados comerciais do cardápio: texto, benefícios, categoria, ícone, ordem e valor mensal (opcional). */
    public function commercial(Request $request, PlatformModule $module, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()?->is_root_admin, 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:1000'],
            'benefits' => ['nullable', 'array', 'max:8'],
            'benefits.*' => ['nullable', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:40'],
            'icon' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'monthly_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999'],
        ], ['name.required' => 'Informe o nome comercial.', 'description.required' => 'Descreva a aplicação.']);
        // Linhas em branco chegam nulas (ConvertEmptyStringsToNull) e são descartadas.
        $validated['benefits'] = array_values(array_filter(array_map(fn (?string $benefit): string => trim((string) $benefit), $validated['benefits'] ?? []), fn (string $benefit): bool => $benefit !== ''));
        $before = $module->only(['name', 'monthly_price']);
        $module->update($validated);
        $audit->record('module.commercial_updated', null, $request->user(), $module, ['before' => $before, 'after' => $module->only(['name', 'monthly_price'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$module->name}: dados comerciais salvos."]);

        return to_route('root-admin.modules.index');
    }
}
