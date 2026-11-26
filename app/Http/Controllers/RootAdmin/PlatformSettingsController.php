<?php

namespace App\Http\Controllers\RootAdmin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações da plataforma em modo consulta. Os valores vêm do ambiente (.env/config) e são alterados na
 * implantação; segredos nunca são exibidos.
 */
class PlatformSettingsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('root-admin/settings', [
            'settings' => [
                ['group' => 'Aplicação', 'label' => 'Nome', 'value' => config('app.name')],
                ['group' => 'Aplicação', 'label' => 'Ambiente', 'value' => app()->environment()],
                ['group' => 'Aplicação', 'label' => 'URL', 'value' => config('app.url')],
                ['group' => 'Aplicação', 'label' => 'Modo de depuração', 'value' => config('app.debug') ? 'Ativo' : 'Inativo'],
                ['group' => 'Aplicação', 'label' => 'Fuso horário do servidor', 'value' => config('app.timezone')],
                ['group' => 'Aplicação', 'label' => 'Idioma', 'value' => config('app.locale')],
                ['group' => 'Infraestrutura', 'label' => 'Banco de dados', 'value' => config('database.default')],
                ['group' => 'Infraestrutura', 'label' => 'Fila', 'value' => config('queue.default')],
                ['group' => 'Infraestrutura', 'label' => 'Cache', 'value' => config('cache.default')],
                ['group' => 'Infraestrutura', 'label' => 'Sessão', 'value' => config('session.driver').' · '.config('session.lifetime').' min'],
                ['group' => 'Infraestrutura', 'label' => 'E-mail', 'value' => config('mail.default')],
                ['group' => 'Segurança', 'label' => 'Tentativas de login por minuto', 'value' => '5 por e-mail e IP'],
                ['group' => 'Segurança', 'label' => 'Cadastro de RootAdmin', 'value' => 'Somente pelo comando php artisan rootadmin:create'],
                ['group' => 'Financeiro', 'label' => 'Horizonte das recorrências', 'value' => config('finance.recurrence_horizon_days').' dias'],
                ['group' => 'Financeiro', 'label' => 'Anexos', 'value' => implode(', ', config('finance.attachments.mimes')).' · até '.config('finance.attachments.maximum_kilobytes').' KB'],
            ],
        ]);
    }
}
