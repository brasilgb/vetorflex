<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('status', 20);
            $table->date('received_at');
            $table->string('document_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_cost', 15, 2)->default(0);
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
            $table->index(['company_id', 'status', 'received_at']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->unsignedBigInteger('purchase_order_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 15, 4);
            $table->decimal('acquisition_total', 15, 2);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('purchase_unit_cost', 15, 4);
            $table->decimal('returned_quantity', 15, 4)->default(0);
            $table->string('divergence', 500)->nullable();
            $table->unsignedBigInteger('inventory_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['purchase_receipt_id', 'purchase_order_item_id'], 'purchase_receipt_items_receipt_item_unique');
            $table->index(['company_id', 'product_variant_id', 'id'], 'purchase_receipt_items_variant_index');
            $table->foreign(['company_id', 'purchase_receipt_id'], 'purchase_receipt_items_receipt_foreign')->references(['company_id', 'id'])->on('purchase_receipts')->restrictOnDelete();
            $table->foreign(['company_id', 'purchase_order_item_id'], 'purchase_receipt_items_order_item_foreign')->references(['company_id', 'id'])->on('purchase_order_items')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'], 'purchase_receipt_items_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'inventory_movement_id'], 'purchase_receipt_items_movement_foreign')->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->date('returned_at');
            $table->string('reason', 500);
            $table->decimal('total_value', 15, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'returned_at']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'purchase_receipt_id'])->references(['company_id', 'id'])->on('purchase_receipts')->restrictOnDelete();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('purchase_receipt_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 15, 4);
            $table->decimal('historical_value', 15, 2);
            $table->decimal('stock_value', 15, 2)->nullable();
            $table->unsignedBigInteger('inventory_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['purchase_return_id', 'purchase_receipt_item_id'], 'purchase_return_items_return_item_unique');
            $table->foreign(['company_id', 'purchase_return_id'], 'purchase_return_items_return_foreign')->references(['company_id', 'id'])->on('purchase_returns')->restrictOnDelete();
            $table->foreign(['company_id', 'purchase_receipt_item_id'], 'purchase_return_items_receipt_item_foreign')->references(['company_id', 'id'])->on('purchase_receipt_items')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'], 'purchase_return_items_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'inventory_movement_id'], 'purchase_return_items_movement_foreign')->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
    }
};
