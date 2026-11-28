<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Cadastro interativo de um administrador geral da plataforma. A senha é sempre digitada (nunca passada por
 * argumento, para não ficar no histórico do terminal) e armazenada com hash. Um e-mail já cadastrado é recusado:
 * o comando não eleva usuários existentes.
 */
#[Signature('rootadmin:create {--name= : Nome do administrador} {--email= : E-mail de acesso}')]
#[Description('Cadastra um administrador geral (RootAdmin) da plataforma')]
class CreateRootAdmin extends Command
{
    public function handle(AuditService $audit): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Nome')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
        $password = (string) $this->secret('Senha (mínimo de 8 caracteres; pode ser só números)');
        $confirmation = (string) $this->secret('Confirme a senha');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
            [
                'password.required' => 'Informe a senha.',
                'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
                'email.unique' => 'Já existe um usuário com este e-mail. O comando não eleva usuários existentes.',
                'password.confirmed' => 'A confirmação da senha não confere.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_root_admin' => true, 'email_verified_at' => now()])->save();
        $audit->record('root_admin.created', null, null, $user, ['email' => $email, 'via' => 'console']);
        $this->info("Administrador {$email} cadastrado. Acesse /login; após entrar, você será levado a /rootadmin.");

        return self::SUCCESS;
    }
}
