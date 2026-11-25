<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id')->unique();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();

            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->string('type', 20);
            $table->decimal('quantity', 15, 4);
            $table->decimal('balance_before', 15, 4);
            $table->decimal('balance_after', 15, 4);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->string('origin', 30);
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'occurred_at', 'id']);
            $table->index(['product_variant_id', 'occurred_at', 'id']);
            $table->index(['company_id', 'reference_type', 'reference_id'], 'inventory_movements_reference_index');
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of_id'])->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
    }
};
