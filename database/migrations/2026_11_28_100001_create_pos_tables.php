<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDV — Frente de Caixa. A venda comercial é a mesma de Vendas (`sale_orders`); estas tabelas guardam somente o
 * controle operacional do caixa: terminais, sessões, carrinhos suspensos, pagamentos informados, sangrias,
 * suprimentos, restituições e favoritos.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('code', 30);
            $table->string('location', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('pos_terminal_operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pos_terminal_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pos_terminal_id', 'user_id']);
            $table->foreign(['company_id', 'pos_terminal_id'])->references(['company_id', 'id'])->on('pos_terminals')->cascadeOnDelete();
        });

        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('pos_terminal_id');
            // Preenchido somente enquanto a sessão está aberta: o índice único impede duas sessões abertas no terminal.
            $table->unsignedBigInteger('open_terminal_id')->nullable()->unique();
            $table->string('status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->decimal('opening_amount', 15, 2);
            $table->text('opening_notes')->nullable();
            $table->timestamp('opened_at');
            $table->boolean('is_blind')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('divergence', 15, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->json('summary')->nullable();
            $table->string('idempotency_key', 100);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'opened_at']);
            $table->foreign(['company_id', 'pos_terminal_id'])->references(['company_id', 'id'])->on('pos_terminals')->restrictOnDelete();
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pos_session_id');
            $table->unsignedBigInteger('pos_terminal_id');
            $table->unsignedBigInteger('sale_order_id')->nullable()->unique();
            $table->string('status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->unsignedBigInteger('customer_party_id')->nullable();
            $table->string('label', 120)->nullable();
            $table->json('cart')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('received_total', 15, 2)->default(0);
            $table->decimal('term_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            $table->decimal('refunded_total', 15, 2)->default(0);
            $table->string('authorized_by_name')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->string('cancellation_reason', 400)->nullable();
            $table->unsignedInteger('reprints')->default(0);
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'completed_at']);
            $table->index(['pos_session_id', 'status']);
            $table->foreign(['company_id', 'pos_session_id'])->references(['company_id', 'id'])->on('pos_sessions')->restrictOnDelete();
            $table->foreign(['company_id', 'pos_terminal_id'])->references(['company_id', 'id'])->on('pos_terminals')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_order_id'])->references(['company_id', 'id'])->on('sale_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_party_id'])->references(['company_id', 'id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pos_sale_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('method', 30);
            $table->decimal('amount', 15, 2);
            $table->decimal('tendered', 15, 2)->nullable();
            $table->decimal('change', 15, 2)->default(0);
            $table->boolean('is_received');
            $table->date('due_date');
            $table->unsignedSmallInteger('schedule_sequence')->nullable();
            $table->timestamps();

            $table->unique(['pos_sale_id', 'sequence']);
            $table->index(['company_id', 'method']);
            $table->foreign(['company_id', 'pos_sale_id'])->references(['company_id', 'id'])->on('pos_sales')->cascadeOnDelete();
        });

        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pos_session_id');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 400);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authorized_by_name')->nullable();
            $table->boolean('is_post_close')->default(false);
            $table->unsignedBigInteger('pos_sale_id')->nullable();
            $table->timestamp('occurred_at');
            $table->string('idempotency_key', 100);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['pos_session_id', 'type']);
            $table->foreign(['company_id', 'pos_session_id'])->references(['company_id', 'id'])->on('pos_sessions')->restrictOnDelete();
        });

        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('pos_sale_id');
            $table->unsignedBigInteger('sale_return_id')->unique();
            $table->unsignedBigInteger('pos_session_id')->nullable();
            $table->string('method', 30);
            $table->decimal('amount', 15, 2);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('authorized_by_name')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->foreign(['company_id', 'pos_sale_id'])->references(['company_id', 'id'])->on('pos_sales')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_return_id'])->references(['company_id', 'id'])->on('sale_returns')->restrictOnDelete();
        });

        Schema::create('pos_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'product_variant_id']);
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_favorites');
        Schema::dropIfExists('pos_refunds');
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_terminal_operators');
        Schema::dropIfExists('pos_terminals');
    }
};
