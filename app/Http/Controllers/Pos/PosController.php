<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Enums\PosPermission;
use App\Modules\Tenancy\Concerns\InteractsWithCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base dos controladores do PDV: empresa ativa, permissões individuais e avisos.
 */
abstract class PosController extends Controller
{
    use InteractsWithCompany;

    protected function can(Request $request, PosPermission $permission): bool
    {
        return $this->allows($request, $permission->value);
    }

    protected function today(Request $request): string
    {
        return now($this->company($request)->timezone)->format('Y-m-d');
    }

    protected function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }

    /** @return array{email?: string|null, password?: string|null}|null */
    protected function credentials(Request $request): ?array
    {
        $credentials = $request->input('authorization');

        return is_array($credentials) ? ['email' => $credentials['email'] ?? null, 'password' => $credentials['password'] ?? null] : null;
    }
}
