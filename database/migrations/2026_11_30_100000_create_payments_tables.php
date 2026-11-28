<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo Pagamentos: contas da empresa nos provedores (credenciais criptografadas), cobranças com estados
     * internos próprios, transações confirmadas pelo provedor, devoluções, histórico e eventos de webhook.
     * Aditiva; o PDV ganha o vínculo opcional do pagamento com a cobrança confirmada. Nomes de chave explícitos
     * respeitam o limite de 64 caracteres do MySQL/MariaDB.
     */
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 30)->unique();
            $table->string('name', 80);
            $table->boolean('is_available')->default(false);
            $table->timestamps();
        });

        Schema::create('payment_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('environment', 20);
            $table->string('name', 80);
            $table->text('credentials')->nullable();
            $table->string('pix_key', 120)->nullable();
            $table->json('methods')->nullable();
            $table->unsignedInteger('expiration_seconds')->default(3600);
            $table->text('webhook_token')->nullable();
            $table->char('webhook_token_hash', 64)->nullable()->unique();
            $table->timestamp('webhook_registered_at')->nullable();
            $table->string('status', 20);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedBigInteger('finance_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'provider', 'environment'], 'payment_accounts_provider_unique');
        });

        Schema::create('payment_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('payment_provider_account_id')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('environment', 20)->nullable();
            $table->string('method', 20);
            $table->string('status', 20);
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->string('description', 200);
            $table->string('reference', 60)->nullable();
            $table->date('due_date');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('payer_party_id')->nullable();
            $table->string('payer_name', 160)->nullable();
            $table->string('payer_document', 20)->nullable();
            $table->string('origin', 20);
            $table->string('origin_type', 40)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->string('origin_label', 160)->nullable();
            $table->string('active_origin_key', 80)->nullable();
            $table->string('provider_charge_id', 60)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->unsignedBigInteger('provider_location_id')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->mediumText('qr_code_image')->nullable();
            $table->string('payment_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->string('cancellation_reason', 400)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key'], 'payment_charges_idempotency_unique');
            $table->unique(['company_id', 'active_origin_key'], 'payment_charges_active_origin_unique');
            $table->unique(['payment_provider_account_id', 'provider_charge_id'], 'payment_charges_provider_unique');
            $table->index(['company_id', 'status', 'due_date'], 'payment_charges_status_index');
            $table->index(['company_id', 'origin_type', 'origin_id'], 'payment_charges_origin_index');
            $table->foreign(['company_id', 'payment_provider_account_id'], 'payment_charges_account_foreign')->references(['company_id', 'id'])->on('payment_provider_accounts')->restrictOnDelete();
        });

        Schema::create('payment_charge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_charge_id');
            $table->string('event', 40);
            $table->string('description', 500);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_charge_id', 'id']);
            $table->foreign(['company_id', 'payment_charge_id'], 'payment_charge_events_charge_foreign')->references(['company_id', 'id'])->on('payment_charges')->cascadeOnDelete();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_charge_id');
            $table->string('provider_transaction_id', 60);
            $table->decimal('amount', 15, 2);
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->timestamp('paid_at');
            $table->string('payer_name', 160)->nullable();
            $table->string('payer_document', 20)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'provider_transaction_id'], 'payment_transactions_provider_unique');
            $table->foreign(['company_id', 'payment_charge_id'], 'payment_transactions_charge_foreign')->references(['company_id', 'id'])->on('payment_charges')->restrictOnDelete();
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payment_charge_id');
            $table->unsignedBigInteger('payment_transaction_id');
            $table->string('provider_refund_id', 35);
            $table->string('rtr_id', 60)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20);
            $table->string('reason', 400);
            $table->string('failure_reason', 400)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 100);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key'], 'payment_refunds_idempotency_unique');
            $table->unique(['payment_transaction_id', 'provider_refund_id'], 'payment_refunds_provider_unique');
            $table->foreign(['company_id', 'payment_charge_id'], 'payment_refunds_charge_foreign')->references(['company_id', 'id'])->on('payment_charges')->restrictOnDelete();
            $table->foreign(['company_id', 'payment_transaction_id'], 'payment_refunds_transaction_foreign')->references(['company_id', 'id'])->on('payment_transactions')->restrictOnDelete();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('payment_provider_account_id')->nullable();
            $table->string('provider', 30);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->string('received_ip', 45)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_provider_account_id', 'payload_hash'], 'payment_webhook_events_payload_unique');
            $table->index(['status', 'id']);
            $table->index(['company_id', 'id']);
        });

        Schema::table('pos_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_charge_id')->nullable()->unique()->after('schedule_sequence');
            $table->boolean('is_provider_confirmed')->default(false)->after('payment_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropUnique(['payment_charge_id']);
            $table->dropColumn(['payment_charge_id', 'is_provider_confirmed']);
        });
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_charge_events');
        Schema::dropIfExists('payment_charges');
        Schema::dropIfExists('payment_provider_accounts');
        Schema::dropIfExists('payment_providers');
    }
};
