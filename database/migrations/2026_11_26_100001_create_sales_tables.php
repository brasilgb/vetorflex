<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orçamentos, pedidos de venda, vencimentos previstos e histórico de situação.
     * Itens avulsos não têm unidade comercializável; cliente nulo indica venda sem identificação.
     */
    public function up(): void
    {
        Schema::create('sale_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('status', 30);
            $table->unsignedBigInteger('customer_party_id')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('seller_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->date('issue_date');
            $table->date('valid_until');
            $table->decimal('items_total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('freight', 15, 2)->default(0);
            $table->decimal('other_costs', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('payment_condition', 30);
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_notes', 500)->nullable();
            $table->text('commercial_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'valid_until']);
            $table->index(['company_id', 'issue_date']);
            $table->foreign(['company_id', 'customer_party_id'])->references(['company_id', 'id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('sale_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('status', 30);
            $table->string('origin', 30);
            $table->unsignedBigInteger('sale_quote_id')->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->unsignedBigInteger('customer_party_id')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('seller_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('delivery_method', 20);
            $table->json('delivery_address')->nullable();
            $table->decimal('items_total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('freight', 15, 2)->default(0);
            $table->decimal('other_costs', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('payment_condition', 30);
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_notes', 500)->nullable();
            $table->text('commercial_terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('is_quick')->default(false);
            $table->boolean('reserves_stock')->default(false);
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('balance_closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->unique('sale_quote_id');
            $table->index(['company_id', 'status', 'order_date']);
            $table->index(['company_id', 'order_date']);
            $table->index(['company_id', 'customer_party_id']);
            $table->foreign(['company_id', 'customer_party_id'])->references(['company_id', 'id'])->on('parties')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_quote_id'])->references(['company_id', 'id'])->on('sale_quotes')->restrictOnDelete();
        });

        foreach (['sale_quote_items' => 'sale_quote', 'sale_order_items' => 'sale_order'] as $tableName => $parent) {
            Schema::create($tableName, function (Blueprint $table) use ($parent) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger("{$parent}_id");
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->unsignedSmallInteger('position');
                $table->string('description');
                $table->string('sku', 60)->nullable();
                $table->string('unit_code', 10);
                $table->boolean('allows_fraction');
                $table->boolean('is_service')->default(false);
                $table->boolean('is_custom')->default(false);
                $table->boolean('controls_stock')->default(false);
                $table->decimal('quantity', 15, 4);
                $table->decimal('catalog_price', 15, 2)->nullable();
                $table->decimal('unit_price', 15, 2);
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('total', 15, 2);
                $table->string('notes', 500)->nullable();

                if ($parent === 'sale_order') {
                    $table->decimal('net_total', 15, 2)->nullable();
                    $table->decimal('fulfilled_quantity', 15, 4)->default(0);
                    $table->decimal('returned_quantity', 15, 4)->default(0);
                }

                $table->timestamps();

                $table->unique(['company_id', 'id']);
                $table->unique(["{$parent}_id", 'position']);
                $table->index(['company_id', 'product_variant_id']);
                $table->foreign(['company_id', "{$parent}_id"])->references(['company_id', 'id'])->on("{$parent}s")->restrictOnDelete();
                $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            });
        }

        Schema::create('sale_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_quote_id')->nullable();
            $table->unsignedBigInteger('sale_order_id')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_entry')->default(false);
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('method', 30)->nullable();
            $table->timestamps();

            $table->unique(['sale_quote_id', 'sequence']);
            $table->unique(['sale_order_id', 'sequence']);
            $table->index(['company_id', 'due_date']);
            $table->foreign(['company_id', 'sale_quote_id'])->references(['company_id', 'id'])->on('sale_quotes')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_order_id'])->references(['company_id', 'id'])->on('sale_orders')->restrictOnDelete();
        });

        Schema::create('sale_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_quote_id')->nullable();
            $table->unsignedBigInteger('sale_order_id')->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['sale_quote_id', 'id']);
            $table->index(['sale_order_id', 'id']);
            $table->foreign(['company_id', 'sale_quote_id'])->references(['company_id', 'id'])->on('sale_quotes')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_order_id'])->references(['company_id', 'id'])->on('sale_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_status_history');
        Schema::dropIfExists('sale_payment_schedules');
        Schema::dropIfExists('sale_order_items');
        Schema::dropIfExists('sale_quote_items');
        Schema::dropIfExists('sale_orders');
        Schema::dropIfExists('sale_quotes');
    }
};
