<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registros de outra empresa respondem 404, antes da validação, para não revelar sua existência.
 */
class EnsureCompanyRecords
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->attributes->get('company')?->id;

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            abort_if($parameter instanceof Model && ($companyId === null || $parameter->getAttribute('company_id') !== $companyId), 404);
        }

        return $next($request);
    }
}
