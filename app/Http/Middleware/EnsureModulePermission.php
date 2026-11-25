<?php

namespace App\Http\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModulePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module, string $permission): Response
    {
        $context = app(TenantContext::class);

        abort_unless(
            $context->enabledModules($request)->contains('identifier', $module)
            && $context->allows($request, $permission),
            403,
        );

        return $next($request);
    }
}
