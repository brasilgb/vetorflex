<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O custo médio nasce nulo: só recebimentos confirmados o definem. O custo cadastrado do produto não muda.
     */
    public function up(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table) {
            $table->decimal('average_cost', 18, 6)->nullable();
        });

        Schema::create('inventory_cost_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('inventory_movement_id')->unique();
            $table->decimal('previous_quantity', 15, 4);
            $table->decimal('previous_cost', 18, 6)->nullable();
            $table->string('previous_cost_source', 20);
            $table->decimal('entry_value', 15, 2)->nullable();
            $table->decimal('new_quantity', 15, 4);
            $table->decimal('new_cost', 18, 6)->nullable();
            $table->string('origin', 30);
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['product_variant_id', 'id']);
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'inventory_movement_id'])->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_changes');
        Schema::table('inventory_balances', function (Blueprint $table) {
            $table->dropColumn('average_cost');
        });
    }
};
