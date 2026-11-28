<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estrutura comercial: dados de venda do catálogo de aplicações, situação comercial e período de teste da empresa,
     * cadastros públicos (com idempotência e recuperação do provisionamento) e pedidos de contato. Aditiva: empresas
     * existentes ficam como contratadas, sem período de teste.
     */
    public function up(): void
    {
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->string('tagline', 160)->nullable();
            $table->json('benefits')->nullable();
            $table->decimal('monthly_price', 10, 2)->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('document', 14)->nullable();
            $table->string('commercial_status', 20)->default('active')->index();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('contracted_at')->nullable();
            $table->string('origin', 20)->default('admin');
            $table->string('provisioning_key', 100)->nullable()->unique();
        });

        Schema::create('signups', function (Blueprint $table) {
            $table->id();
            $table->char('idempotency_key', 36)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('company_name', 160);
            $table->string('company_document', 14)->nullable();
            $table->json('modules');
            $table->string('terms_version', 20);
            $table->timestamp('accepted_terms_at');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 160);
            $table->string('phone', 30)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->string('message', 2000);
            $table->json('modules')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
        Schema::dropIfExists('signups');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['provisioning_key']);
            $table->dropIndex(['commercial_status']);
            $table->dropColumn(['document', 'commercial_status', 'trial_started_at', 'trial_ends_at', 'contracted_at', 'origin', 'provisioning_key']);
        });
        Schema::table('platform_modules', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'benefits', 'monthly_price']);
        });
    }
};
