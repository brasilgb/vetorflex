<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('supplier_profile_id');
            $table->json('supplier_snapshot');
            $table->string('status', 30);
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->decimal('items_total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('freight', 15, 2)->default(0);
            $table->decimal('insurance', 15, 2)->default(0);
            $table->decimal('other_costs', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('payment_condition', 30);
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_notes', 500)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_quick')->default(false);
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('balance_closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'expected_date']);
            $table->index(['company_id', 'order_date']);
            $table->foreign(['company_id', 'supplier_profile_id'])->references(['company_id', 'id'])->on('supplier_profiles')->restrictOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedSmallInteger('position');
            $table->string('description');
            $table->string('sku', 60)->nullable();
            $table->string('supplier_code', 60)->nullable();
            $table->string('unit_code', 10);
            $table->boolean('allows_fraction');
            $table->boolean('is_service')->default(false);
            $table->boolean('is_free')->default(false);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('acquisition_total', 15, 2)->nullable();
            $table->decimal('received_quantity', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['purchase_order_id', 'position']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('purchase_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_entry')->default(false);
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('method', 30)->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'sequence']);
            $table->index(['company_id', 'due_date']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        Schema::create('purchase_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['purchase_order_id', 'id']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_status_history');
        Schema::dropIfExists('purchase_payment_schedules');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
