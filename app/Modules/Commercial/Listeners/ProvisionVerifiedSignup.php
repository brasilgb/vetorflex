<?php

namespace App\Modules\Commercial\Listeners;

use App\Models\User;
use App\Modules\Commercial\Services\SignupService;
use Illuminate\Auth\Events\Verified;

/** Provisiona a empresa do cadastro público assim que o responsável confirma o e-mail. */
class ProvisionVerifiedSignup
{
    public function __construct(private SignupService $signups) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            $this->signups->provisionPendingFor($event->user);
        }
    }
}
