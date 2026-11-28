<?php

use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Sales\Enums\SaleOrderStatus;
use App\Modules\Sales\Enums\SaleOrigin;
use App\Modules\Sales\Enums\SalePermission;
use App\Modules\Sales\Enums\SaleQuoteStatus;
use App\Modules\Sales\Events\QuoteApproved;
use App\Modules\Sales\Events\QuoteConverted;
use App\Modules\Sales\Models\SaleOrder;
use App\Modules\Sales\Models\SaleQuote;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function quoteData(array $items, array $overrides = []): array
{
    return [
        'customer_party_id' => null,
        'issue_date' => now()->format('Y-m-d'),
        'valid_until' => now()->addDays(10)->format('Y-m-d'),
        'payment_condition' => 'undefined',
        'installments' => [],
        'items' => $items,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function issuedQuote(Company $company, array $items, array $overrides = []): SaleQuote
{
    test()->post('/sales/quotes', quoteData($items, $overrides))->assertSessionHasNoErrors();
    $quote = SaleQuote::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    test()->post("/sales/quotes/{$quote->id}/issue")->assertSessionHasNoErrors();

    return $quote->refresh();
}

test('a quote goes from draft to issued and approved without moving stock', function () {
    Event::fake([QuoteApproved::class]);
    $company = Company::factory()->create();
    actingAsSalesMember($company, withStock: true);
    $variant = sellableVariant($company, '15.00', stock: '5');

    $quote = issuedQuote($company, [['product_variant_id' => $variant->id, 'quantity' => '3']], ['freight' => '5', 'commercial_terms' => 'Entrega em 5 dias']);
    $this->post("/sales/quotes/{$quote->id}/approve")->assertSessionHasNoErrors();

    expect($quote->fresh())->number->toBe(1)->status->toBe(SaleQuoteStatus::Approved)->total->toBe('50.00');
    expect($quote->statusHistory()->pluck('to_status')->all())->toBe(['draft', 'issued', 'approved']);
    expect(InventoryMovement::query()->where('origin', 'sale')->count())->toBe(0);
    expect(InventoryBalance::query()->where('product_variant_id', $variant->id)->value('reserved_quantity'))->toBe('0.0000');
    Event::assertDispatched(QuoteApproved::class);
});

test('conversion creates one draft sale with the quoted prices and keeps the reference', function () {
    Event::fake([QuoteConverted::class]);
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $customer = saleCustomer($company);
    $variant = sellableVariant($company, '40.00');
    $quote = issuedQuote($company, [['product_variant_id' => $variant->id, 'quantity' => '2', 'discount' => '5']], ['customer_party_id' => $customer->id]);
    $this->post("/sales/quotes/{$quote->id}/approve");
    $variant->update(['sale_price' => '99.00']);

    $this->post("/sales/quotes/{$quote->id}/convert")->assertRedirect();
    $this->post("/sales/quotes/{$quote->id}/convert")->assertRedirect();

    $order = SaleOrder::query()->sole();
    expect($order)->status->toBe(SaleOrderStatus::Draft)->origin->toBe(SaleOrigin::Quote)->sale_quote_id->toBe($quote->id)
        ->customer_party_id->toBe($customer->id)->total->toBe('75.00');
    expect($order->items()->sole()->unit_price)->toBe('40.00');
    expect($quote->fresh()->status)->toBe(SaleQuoteStatus::Converted);
    Event::assertDispatchedTimes(QuoteConverted::class, 1);
});

test('only approved quotes can be converted', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $quote = issuedQuote($company, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']]);

    $this->post("/sales/quotes/{$quote->id}/convert")->assertSessionHasErrors('status');
    expect(SaleOrder::query()->count())->toBe(0);
});

test('rejection and cancellation require a reason and are final', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $variant = sellableVariant($company);
    $rejected = issuedQuote($company, [['product_variant_id' => $variant->id, 'quantity' => '1']]);
    $cancelled = issuedQuote($company, [['product_variant_id' => $variant->id, 'quantity' => '1']]);

    $this->post("/sales/quotes/{$rejected->id}/reject", [])->assertSessionHasErrors(['reason' => 'Informe o motivo.']);
    $this->post("/sales/quotes/{$rejected->id}/reject", ['reason' => 'Preço alto'])->assertSessionHasNoErrors();
    $this->post("/sales/quotes/{$cancelled->id}/cancel", ['reason' => 'Duplicado'])->assertSessionHasNoErrors();
    $this->post("/sales/quotes/{$rejected->id}/approve")->assertSessionHasErrors('status');

    expect($rejected->fresh()->status)->toBe(SaleQuoteStatus::Rejected);
    expect($cancelled->fresh()->status)->toBe(SaleQuoteStatus::Cancelled);
});

test('an issued quote past its validity expires instead of being approved', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $quote = issuedQuote($company, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']], ['valid_until' => now()->format('Y-m-d')]);
    $this->travel(2)->days();

    $this->post("/sales/quotes/{$quote->id}/approve")->assertSessionHasErrors(['status' => 'O orçamento expirou e não pode mais ser aprovado ou recusado.']);

    expect($quote->fresh()->status)->toBe(SaleQuoteStatus::Expired);
});

test('the daily command expires overdue quotes of companies with sales', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $overdue = issuedQuote($company, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']], ['valid_until' => now()->format('Y-m-d')]);
    $valid = issuedQuote($company, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']], ['valid_until' => now()->addMonth()->format('Y-m-d')]);
    $this->travel(2)->days();

    $this->artisan('sales:expire-quotes')->assertSuccessful();

    expect($overdue->fresh()->status)->toBe(SaleQuoteStatus::Expired);
    expect($valid->fresh()->status)->toBe(SaleQuoteStatus::Issued);
    expect($overdue->statusHistory()->get()->last()->user_name)->toBe('Sistema');
});

test('approving quotes requires its own permission', function () {
    $company = Company::factory()->create();
    actingAsSalesMember($company);
    $quote = issuedQuote($company, [['product_variant_id' => sellableVariant($company)->id, 'quantity' => '1']]);
    actingAsSalesMember($company, [SalePermission::View, SalePermission::ManageQuotes]);

    $this->post("/sales/quotes/{$quote->id}/approve")->assertForbidden();
    expect($quote->fresh()->status)->toBe(SaleQuoteStatus::Issued);
});
