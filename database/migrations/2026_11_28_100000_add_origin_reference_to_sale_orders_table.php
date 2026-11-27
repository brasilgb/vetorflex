<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referência legível do canal que originou a venda (por exemplo, "Caixa 01 · sessão 12" no PDV).
     */
    public function up(): void
    {
        Schema::table('sale_orders', function (Blueprint $table) {
            $table->string('origin_reference', 160)->nullable()->after('origin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_orders', function (Blueprint $table) {
            $table->dropColumn('origin_reference');
        });
    }
};
