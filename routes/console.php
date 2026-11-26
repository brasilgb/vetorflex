<?php

use App\Modules\Products\Models\ProductImport;
use App\Modules\Purchases\Models\SupplierImport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune', ['--model' => [ProductImport::class, SupplierImport::class]])->daily();
