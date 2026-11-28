<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atendimentos (retirada, entrega, serviço) e devoluções. O custo é histórico: gravado no atendimento e nunca recalculado.
     */
    public function up(): void
    {
        Schema::create('sale_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('sale_order_id');
            $table->string('status', 20);
            $table->string('method', 20);
            $table->date('fulfilled_at');
            $table->text('notes')->nullable();
            $table->decimal('revenue', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by_name')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'fulfilled_at']);
            $table->foreign(['company_id', 'sale_order_id'])->references(['company_id', 'id'])->on('sale_orders')->restrictOnDelete();
        });

        Schema::create('sale_fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_fulfillment_id');
            $table->unsignedBigInteger('sale_order_item_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('revenue', 15, 2);
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('cost_total', 15, 2)->nullable();
            $table->decimal('returned_quantity', 15, 4)->default(0);
            $table->unsignedBigInteger('inventory_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['sale_fulfillment_id', 'sale_order_item_id'], 'sale_fulfillment_items_item_unique');
            $table->index(['company_id', 'product_variant_id']);
            $table->foreign(['company_id', 'sale_fulfillment_id'], 'sale_fulfillment_items_fulfillment_foreign')->references(['company_id', 'id'])->on('sale_fulfillments')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_order_item_id'], 'sale_fulfillment_items_order_item_foreign')->references(['company_id', 'id'])->on('sale_order_items')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'], 'sale_fulfillment_items_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'inventory_movement_id'], 'sale_fulfillment_items_movement_foreign')->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('sale_order_id');
            $table->unsignedBigInteger('sale_fulfillment_id');
            $table->date('returned_at');
            $table->string('reason', 500);
            $table->boolean('restock')->default(true);
            $table->decimal('total_value', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'returned_at']);
            $table->foreign(['company_id', 'sale_order_id'])->references(['company_id', 'id'])->on('sale_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_fulfillment_id'])->references(['company_id', 'id'])->on('sale_fulfillments')->restrictOnDelete();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_return_id');
            $table->unsignedBigInteger('sale_fulfillment_item_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('historical_value', 15, 2);
            $table->decimal('cost_value', 15, 2)->nullable();
            $table->unsignedBigInteger('inventory_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['sale_return_id', 'sale_fulfillment_item_id'], 'sale_return_items_item_unique');
            $table->foreign(['company_id', 'sale_return_id'], 'sale_return_items_return_foreign')->references(['company_id', 'id'])->on('sale_returns')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_fulfillment_item_id'], 'sale_return_items_fulfillment_item_foreign')->references(['company_id', 'id'])->on('sale_fulfillment_items')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'], 'sale_return_items_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'inventory_movement_id'], 'sale_return_items_movement_foreign')->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_fulfillment_items');
        Schema::dropIfExists('sale_fulfillments');
    }
};
