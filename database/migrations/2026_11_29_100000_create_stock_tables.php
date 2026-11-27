<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo Estoque sobre o núcleo existente: depósitos com saldo por depósito, transferências, inventários (contagem
     * física) e estoque máximo. Aditiva: empresas com saldo recebem um depósito principal que assume todo o saldo e as
     * movimentações já registradas; `inventory_balances` continua sendo o saldo total da empresa por unidade.
     */
    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('code', 30);
            $table->string('location', 160)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('default_company_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('inventory_warehouse_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('inventory_warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['inventory_warehouse_id', 'product_variant_id'], 'inventory_warehouse_balances_unique');
            $table->index(['company_id', 'product_variant_id'], 'inventory_warehouse_balances_variant_index');
            $table->foreign(['company_id', 'inventory_warehouse_id'], 'inventory_warehouse_balances_warehouse_foreign')->references(['company_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'], 'inventory_warehouse_balances_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('idempotency_key', 100);
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->foreign(['company_id', 'from_warehouse_id'], 'inventory_transfers_from_foreign')->references(['company_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'to_warehouse_id'], 'inventory_transfers_to_foreign')->references(['company_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_warehouse_id')->nullable()->after('product_variant_id');
            $table->decimal('warehouse_balance_after', 15, 4)->nullable()->after('balance_after');
            $table->index(['inventory_warehouse_id', 'occurred_at'], 'inventory_movements_warehouse_index');
            $table->foreign(['company_id', 'inventory_warehouse_id'], 'inventory_movements_warehouse_foreign')->references(['company_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('maximum_stock', 15, 4)->nullable()->after('minimum_stock');
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('inventory_warehouse_id');
            $table->string('status', 20);
            $table->string('description', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 400)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'inventory_warehouse_id'], 'inventory_counts_warehouse_foreign')->references(['company_id', 'id'])->on('inventory_warehouses')->restrictOnDelete();
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('inventory_count_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('counted_quantity', 15, 4)->nullable();
            $table->decimal('system_quantity', 15, 4)->nullable();
            $table->decimal('difference', 15, 4)->nullable();
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['inventory_count_id', 'product_variant_id'], 'inventory_count_items_variant_unique');
            $table->foreign(['company_id', 'inventory_count_id'], 'inventory_count_items_count_foreign')->references(['company_id', 'id'])->on('inventory_counts')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'], 'inventory_count_items_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['company_id', 'inventory_movement_id'], 'inventory_count_items_movement_foreign')->references(['company_id', 'id'])->on('inventory_movements')->restrictOnDelete();
        });

        $this->backfillDefaultWarehouses();
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('maximum_stock');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign('inventory_movements_warehouse_foreign');
            $table->dropIndex('inventory_movements_warehouse_index');
            $table->dropColumn(['inventory_warehouse_id', 'warehouse_balance_after']);
        });
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_warehouse_balances');
        Schema::dropIfExists('inventory_warehouses');
    }

    /** Depósito principal para cada empresa com saldo ou movimentação, assumindo o saldo atual e o histórico. */
    private function backfillDefaultWarehouses(): void
    {
        $companyIds = DB::table('inventory_balances')->distinct()->pluck('company_id')
            ->merge(DB::table('inventory_movements')->distinct()->pluck('company_id'))->unique()->values();
        $now = now();

        foreach ($companyIds as $companyId) {
            $warehouseId = DB::table('inventory_warehouses')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Depósito principal',
                'code' => 'PRINCIPAL',
                'is_default' => true,
                'is_active' => true,
                'default_company_id' => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('inventory_balances')->where('company_id', $companyId)->orderBy('id')->each(function (object $balance) use ($warehouseId, $now): void {
                DB::table('inventory_warehouse_balances')->insert([
                    'company_id' => $balance->company_id,
                    'inventory_warehouse_id' => $warehouseId,
                    'product_variant_id' => $balance->product_variant_id,
                    'quantity' => $balance->quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            DB::table('inventory_movements')->where('company_id', $companyId)->update(['inventory_warehouse_id' => $warehouseId]);
        }
    }
};
