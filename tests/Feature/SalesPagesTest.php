<?php

use App\Models\User;
use App\Modules\Sales\Models\SaleFulfillment;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('every sales page renders for the demo company', function () {
    $this->seed(DatabaseSeeder::class);
    $company = Company::query()->where('slug', 'empresa-demonstracao')->sole();
    $this->actingAs(User::query()->where('email', 'demo@vetornew.test')->sole())->withSession(['active_company_id' => $company->id]);
    $quote = SaleQuote::query()->where('company_id', $company->id)->where('status', 'issued')->sole();
    $draft = SaleOrder::query()->where('company_id', $company->id)->where('status', 'draft')->sole();
    $completed = SaleOrder::query()->where('company_id', $company->id)->where('status', 'completed')->sole();
    $this->post('/sales/quotes', ['issue_date' => now()->format('Y-m-d'), 'valid_until' => now()->addDay()->format('Y-m-d'), 'payment_condition' => 'undefined', 'items' => [['product_variant_id' => $draft->items()->first()->product_variant_id, 'quantity' => '1']], 'idempotency_key' => (string) Str::uuid()]);
    $draftQuote = SaleQuote::query()->latest('id')->first();

    foreach ([
        '/sales' => 'sales/dashboard',
        '/sales/quotes' => 'sales/quotes/index',
        '/sales/quotes/create' => 'sales/quotes/form',
        "/sales/quotes/{$quote->id}" => 'sales/quotes/show',
        "/sales/quotes/{$draftQuote->id}/edit" => 'sales/quotes/form',
        '/sales/orders' => 'sales/orders/index',
        '/sales/orders/create?quick=1' => 'sales/orders/form',
        "/sales/orders/{$draft->id}/edit" => 'sales/orders/form',
        "/sales/orders/{$completed->id}" => 'sales/orders/show',
        '/sales/fulfillments' => 'sales/fulfillments',
        '/sales/returns' => 'sales/returns',
    ] as $path => $component) {
        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component));
    }

    foreach (["/sales/quotes/{$quote->id}/document", '/sales/returns/'.SaleReturn::query()->sole()->id.'/document', '/sales/fulfillments/'.SaleFulfillment::query()->first()->id.'/document'] as $path) {
        expect($this->get($path)->assertOk()->getContent())->toStartWith('%PDF-1.4');
    }
});
