<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use App\Modules\ModuleManager\Models\PlatformModule;
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
}
