<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo Documentos Fiscais: provedores e tipos liberados pela plataforma, conta da plataforma no provedor
     * (credenciais criptografadas), configuração fiscal por empresa, documentos, histórico e webhooks. Os produtos
     * compartilhados ganham os campos fiscais opcionais de mercadorias e serviços. Aditiva; nomes de chave explícitos.
     */
    public function up(): void
    {
        Schema::create('fiscal_providers', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 30)->unique();
            $table->string('name', 80);
            $table->boolean('is_available')->default(false);
            $table->json('document_types')->nullable();
            $table->string('environment', 20)->default('sandbox');
            $table->text('platform_credentials')->nullable();
            $table->timestamp('webhook_registered_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name', 80)->nullable();
            $table->string('trade_name', 80)->nullable();
            $table->string('cnpj', 14)->nullable();
            $table->string('state_registration', 20)->nullable();
            $table->string('municipal_registration', 20)->nullable();
            $table->string('email', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->json('address')->nullable();
            $table->string('city_code', 7)->nullable();
            $table->string('tax_regime', 40)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('environment', 20)->default('homologation');
            $table->json('enabled_types')->nullable();
            $table->string('provider_company_id', 60)->nullable();
            $table->text('provider_credentials')->nullable();
            $table->string('provider_environment', 20)->nullable();
            $table->string('registration_status', 20)->default('pending');
            $table->string('registration_error', 1000)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->string('certificate_subject')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->json('series')->nullable();
            $table->json('nfse')->nullable();
            $table->json('product_defaults')->nullable();
            $table->text('nfce_csc')->nullable();
            $table->string('nfce_csc_id', 10)->nullable();
            $table->timestamp('tax_settings_confirmed_at')->nullable();
            $table->string('tax_settings_confirmed_by')->nullable();
            $table->timestamp('production_released_at')->nullable();
            $table->string('production_released_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('local_number');
            $table->string('type', 10);
            $table->string('status', 30);
            $table->string('origin', 20);
            $table->string('origin_type', 40)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->string('origin_label', 160)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('environment', 20)->nullable();
            $table->char('integration_id', 36)->unique();
            $table->unsignedBigInteger('receiver_party_id')->nullable();
            $table->json('receiver')->nullable();
            $table->json('items');
            $table->json('tax_data')->nullable();
            $table->string('description', 2000)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('items_total', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('provider_reference', 60)->nullable();
            $table->string('provider_status', 30)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('series', 10)->nullable();
            $table->string('access_key', 60)->nullable();
            $table->string('authorization_protocol', 60)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('xml_sha256', 64)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->string('idempotency_key', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'local_number']);
            $table->unique(['company_id', 'idempotency_key'], 'fiscal_documents_idempotency_unique');
            $table->index(['company_id', 'status'], 'fiscal_documents_status_index');
            $table->index(['company_id', 'origin_type', 'origin_id'], 'fiscal_documents_origin_index');
            $table->index(['provider', 'provider_reference'], 'fiscal_documents_reference_index');
        });

        Schema::create('fiscal_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_document_id');
            $table->string('event', 40);
            $table->string('description', 1000);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['fiscal_document_id', 'id']);
            $table->foreign(['company_id', 'fiscal_document_id'], 'fiscal_document_events_document_foreign')->references(['company_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();
        });

        Schema::create('fiscal_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 64);
            $table->string('event', 60);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('fiscal_document_id')->nullable();
            $table->json('data')->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'fiscal_webhook_events_unique');
            $table->index(['status', 'id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('cfop', 4)->nullable();
            $table->string('icms_situation', 4)->nullable();
            $table->string('pis_situation', 2)->nullable();
            $table->string('cofins_situation', 2)->nullable();
            $table->string('service_code', 10)->nullable();
            $table->string('service_city_code', 7)->nullable();
            $table->string('tax_unit', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cfop', 'icms_situation', 'pis_situation', 'cofins_situation', 'service_code', 'service_city_code', 'tax_unit']);
        });
        Schema::dropIfExists('fiscal_webhook_events');
        Schema::dropIfExists('fiscal_document_events');
        Schema::dropIfExists('fiscal_documents');
        Schema::dropIfExists('fiscal_settings');
        Schema::dropIfExists('fiscal_providers');
    }
};
