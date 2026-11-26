<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe o painel administrativo da plataforma ao RootAdmin. Usuários empresariais recebem 403.
 */
class EnsureRootAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_root_admin === true, 403, 'Área restrita à administração da plataforma.');

        return $next($request);
    }
}
