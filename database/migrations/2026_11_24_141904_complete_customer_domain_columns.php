<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('state_registration', 30)->nullable();
            $table->string('municipal_registration', 30)->nullable();
            $table->string('whatsapp')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('notes')->nullable();
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'email']);
        });

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_terms', 100)->nullable();
            $table->string('classification', 50)->nullable();
            $table->index(['company_id', 'party_id']);
            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->cascadeOnDelete();
            $table->unique(['company_id', 'internal_code']);
        });

        Schema::table('party_addresses', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('country', 2)->default('BR');
            $table->index(['company_id', 'party_id']);
            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->cascadeOnDelete();
        });

        Schema::table('party_contacts', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('position')->nullable();
            $table->string('whatsapp')->nullable();
            $table->index(['company_id', 'party_id']);
            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'party_contacts' => ['position', 'whatsapp'],
            'party_addresses' => ['complement', 'district', 'country'],
        ] as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->dropForeign(['company_id', 'party_id']);
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id', 'party_id']);
                $table->dropColumn(['company_id', ...$columns]);
            });
        }

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'party_id']);
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'party_id']);
            $table->dropUnique(['company_id', 'internal_code']);
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn(['company_id', 'payment_terms', 'classification']);
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_active']);
            $table->dropIndex(['company_id', 'email']);
            $table->dropColumn(['state_registration', 'municipal_registration', 'whatsapp', 'birth_date', 'notes']);
        });
    }
};
