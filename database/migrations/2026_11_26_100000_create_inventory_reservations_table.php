<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservas de estoque por documento de origem (venda, PDV, loja virtual). Aditiva: saldos existentes começam sem reserva.
     */
    public function up(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table) {
            $table->decimal('reserved_quantity', 15, 4)->default(0);
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->string('reference_type', 60);
            $table->string('reference_id', 60);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'reference_type', 'reference_id'], 'inventory_reservations_reference_unique');
            $table->index(['product_variant_id', 'quantity']);
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::table('inventory_balances', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });
    }
};
