<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 40);
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('supplier_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('party_id')->unique();
            $table->string('internal_code', 60)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('payment_terms', 100)->nullable();
            $table->decimal('minimum_order', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'internal_code']);
            $table->index(['company_id', 'is_active']);
            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('supplier_profile_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('supplier_code', 60)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('last_cost', 15, 4)->nullable();
            $table->date('last_purchased_at')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('minimum_quantity', 15, 4)->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['supplier_profile_id', 'product_variant_id']);
            $table->index(['company_id', 'product_variant_id']);
            $table->foreign(['company_id', 'supplier_profile_id'])->references(['company_id', 'id'])->on('supplier_profiles')->restrictOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('supplier_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('status', 20)->default('preview');
            $table->longText('rows')->nullable();
            $table->json('report')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_imports');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('supplier_profiles');
        Schema::dropIfExists('company_sequences');
    }
};
