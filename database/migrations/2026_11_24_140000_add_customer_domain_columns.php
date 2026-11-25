<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('document', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['company_id', 'document']);
            $table->index(['company_id', 'name']);
        });
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('internal_code')->nullable();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->text('commercial_notes')->nullable();
            $table->unique('party_id');
        });
        Schema::table('party_addresses', function (Blueprint $table) {
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('type', 20)->default('main');
            $table->string('postal_code', 10)->nullable();
            $table->string('street')->nullable();
            $table->string('number')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
        });
        Schema::table('party_contacts', function (Blueprint $table) {
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'party_contacts' => ['name', 'email', 'phone', 'is_primary'],
            'party_addresses' => ['type', 'postal_code', 'street', 'number', 'city', 'state'],
        ] as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->dropConstrainedForeignId('party_id');
                $table->dropColumn($columns);
            });
        }

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
            $table->dropUnique(['party_id']);
            $table->dropColumn(['party_id', 'internal_code', 'credit_limit', 'commercial_notes']);
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id', 'document']);
            $table->dropIndex(['company_id', 'name']);
            $table->dropColumn(['company_id', 'type', 'name', 'trade_name', 'document', 'email', 'phone', 'mobile', 'is_active']);
        });
    }
};
