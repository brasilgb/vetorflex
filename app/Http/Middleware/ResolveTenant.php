<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = $request->session()->get('active_company_id');

        if ($user === null || $companyId === null) {
            abort(403, 'Selecione uma empresa ativa para acessar esta área.');
        }

        $company = $user->companies()->whereKey($companyId)->wherePivot('is_active', true)->first();

        if ($company === null || $company->status !== 'active') {
            abort(404);
        }

        $request->attributes->set('company', $company);

        return $next($request);
    }
}
