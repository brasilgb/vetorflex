<?php

use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('indicators use real documents of the period and separate sold from cancelled', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $customer = saleCustomer($company, ['name' => 'Cliente Fiel']);
    $variant = sellableVariant($company, '50.00', stock: '20', averageCost: '20.00');
    $this->post('/sales/orders', saleOrderData($customer, [['product_variant_id' => $variant->id, 'quantity' => '2']], ['quick' => true]));
    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '1']], ['quick' => true]));
    $this->post('/sales/orders', saleOrderData(null, [['product_variant_id' => $variant->id, 'quantity' => '9']]));
    $cancelled = SaleOrder::query()->latest('id')->first();
    $this->post("/sales/orders/{$cancelled->id}/cancel", ['reason' => 'Desistência']);
    $this->post('/sales/quotes', ['issue_date' => now()->format('Y-m-d'), 'valid_until' => now()->addWeek()->format('Y-m-d'), 'payment_condition' => 'undefined', 'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']], 'idempotency_key' => (string) Str::uuid()]);
    $this->post('/sales/quotes/'.SaleQuote::query()->sole()->id.'/issue');

    $this->get('/sales')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('sales/dashboard')
        ->where('indicators.sold_value', '150.00')
        ->where('indicators.orders_count', 2)
        ->where('indicators.average_ticket', '75.00')
        ->where('indicators.cancelled_count', 1)
        ->where('indicators.quotes_issued', 1)
        ->where('indicators.conversion_rate', '0.00')
        ->where('indicators.top_customers.0.name', 'Cliente Fiel')
        ->where('indicators.top_products.0.quantity', '3.0000')
        ->where('indicators.margin.margin', '90.00')
        ->where('indicators.margin.percent', '60.00'));
});

test('indicators require their own permission and margins require cost and margin permissions', function () {
    $company = Company::factory()->create();

    actingAsSalesMember($company, [SalePermission::View]);
    $this->get('/sales')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('indicators', null));

    actingAsSalesMember($company, [SalePermission::View, SalePermission::ViewIndicators, SalePermission::ViewMargin]);
    $this->get('/sales')->assertInertia(fn (AssertableInertia $page) => $page->where('indicators.margin', null)->has('indicators.sold_value'));
});
