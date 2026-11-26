<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo Financeiro: contas, categorias, títulos a pagar e a receber, baixas, livro de movimentações,
     * transferências, recorrências, renegociações, ajustes de integração e anexos. Valores com 2 casas decimais.
     */
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->string('institution', 120)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_date');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('kind', 20);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'kind', 'name']);
            $table->foreign(['company_id', 'parent_id'])->references(['company_id', 'id'])->on('finance_categories')->restrictOnDelete();
        });

        Schema::create('finance_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('description');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->unsignedBigInteger('finance_category_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('frequency', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('generated_until')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'ended_at']);
            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_category_id'])->references(['company_id', 'id'])->on('finance_categories')->restrictOnDelete();
        });

        Schema::create('finance_renegotiations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('total', 15, 2);
            $table->string('reason', 500);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
        });

        Schema::create('finance_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('origin', 30);
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name')->nullable();
            $table->string('description');
            $table->unsignedBigInteger('finance_category_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('adjusted_amount', 15, 2)->default(0);
            $table->decimal('settled_principal', 15, 2)->default(0);
            $table->decimal('cancelled_amount', 15, 2)->default(0);
            $table->decimal('interest_total', 15, 2)->default(0);
            $table->decimal('fine_total', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->date('competence_date');
            $table->date('due_date');
            $table->unsignedSmallInteger('installment_number')->default(1);
            $table->unsignedSmallInteger('installment_count')->default(1);
            $table->uuid('group_key')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 120)->nullable();
            $table->string('source_label', 120)->nullable();
            $table->unsignedBigInteger('finance_recurrence_id')->nullable();
            $table->unsignedBigInteger('renegotiation_id')->nullable();
            $table->unsignedBigInteger('renegotiated_in_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'source_key']);
            $table->unique(['company_id', 'idempotency_key', 'installment_number'], 'finance_titles_idempotency_unique');
            $table->index(['company_id', 'kind', 'status', 'due_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'group_key']);
            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_category_id'])->references(['company_id', 'id'])->on('finance_categories')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_recurrence_id'])->references(['company_id', 'id'])->on('finance_recurrences')->restrictOnDelete();
            $table->foreign(['company_id', 'renegotiation_id'])->references(['company_id', 'id'])->on('finance_renegotiations')->restrictOnDelete();
            $table->foreign(['company_id', 'renegotiated_in_id'])->references(['company_id', 'id'])->on('finance_renegotiations')->restrictOnDelete();
        });

        Schema::create('finance_title_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('finance_title_id');
            $table->decimal('amount', 15, 2);
            $table->string('reason', 500);
            $table->string('source_key', 120)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['finance_title_id', 'id']);
            $table->foreign(['company_id', 'finance_title_id'])->references(['company_id', 'id'])->on('finance_titles')->restrictOnDelete();
        });

        Schema::create('finance_title_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('finance_title_id');
            $table->string('event', 30);
            $table->string('description', 500);
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['finance_title_id', 'id']);
            $table->foreign(['company_id', 'finance_title_id'])->references(['company_id', 'id'])->on('finance_titles')->restrictOnDelete();
        });

        Schema::create('finance_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('finance_title_id');
            $table->unsignedBigInteger('finance_account_id')->nullable();
            $table->string('status', 20);
            $table->boolean('is_external')->default(false);
            $table->date('settled_at');
            $table->string('method', 30)->nullable();
            $table->decimal('principal', 15, 2);
            $table->decimal('interest', 15, 2)->default(0);
            $table->decimal('fine', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by_name')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'settled_at']);
            $table->foreign(['company_id', 'finance_title_id'])->references(['company_id', 'id'])->on('finance_titles')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_account_id'])->references(['company_id', 'id'])->on('finance_accounts')->restrictOnDelete();
        });

        Schema::create('finance_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('from_account_id');
            $table->unsignedBigInteger('to_account_id');
            $table->decimal('amount', 15, 2);
            $table->date('transferred_at');
            $table->string('description')->nullable();
            $table->string('status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by_name')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->foreign(['company_id', 'from_account_id'])->references(['company_id', 'id'])->on('finance_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'to_account_id'])->references(['company_id', 'id'])->on('finance_accounts')->restrictOnDelete();
        });

        Schema::create('finance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('finance_account_id');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->boolean('is_operational')->default(false);
            $table->date('occurred_at');
            $table->string('description');
            $table->unsignedBigInteger('finance_category_id')->nullable();
            $table->unsignedBigInteger('finance_settlement_id')->nullable();
            $table->unsignedBigInteger('finance_transfer_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('method', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('idempotency_key', 120);
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reconciled_by_name')->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->unique('reversal_of_id');
            // Nome explícito: o gerado teria 65 caracteres, acima do limite de 64 do MySQL/MariaDB.
            $table->index(['company_id', 'finance_account_id', 'occurred_at'], 'finance_movements_account_occurred_index');
            $table->index(['company_id', 'is_operational', 'occurred_at']);
            $table->foreign(['company_id', 'finance_account_id'])->references(['company_id', 'id'])->on('finance_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_category_id'])->references(['company_id', 'id'])->on('finance_categories')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_settlement_id'])->references(['company_id', 'id'])->on('finance_settlements')->restrictOnDelete();
            $table->foreign(['company_id', 'finance_transfer_id'])->references(['company_id', 'id'])->on('finance_transfers')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of_id'])->references(['company_id', 'id'])->on('finance_movements')->restrictOnDelete();
        });

        Schema::create('finance_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('finance_title_id');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index(['finance_title_id', 'id']);
            $table->foreign(['company_id', 'finance_title_id'])->references(['company_id', 'id'])->on('finance_titles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_attachments');
        Schema::dropIfExists('finance_movements');
        Schema::dropIfExists('finance_transfers');
        Schema::dropIfExists('finance_settlements');
        Schema::dropIfExists('finance_title_events');
        Schema::dropIfExists('finance_title_adjustments');
        Schema::dropIfExists('finance_titles');
        Schema::dropIfExists('finance_renegotiations');
        Schema::dropIfExists('finance_recurrences');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
    }
};
