<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('label')->nullable();
            $table->string('combination_key', 191)->nullable();
            $table->string('sku', 60)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('barcode_type', 20)->nullable();
            $table->decimal('cost_price', 15, 4)->nullable();
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->decimal('promotional_price', 15, 2)->nullable();
            $table->decimal('minimum_stock', 15, 4)->default(0);
            $table->decimal('weight', 12, 4)->nullable();
            $table->decimal('height', 12, 4)->nullable();
            $table->decimal('width', 12, 4)->nullable();
            $table->decimal('length', 12, 4)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['product_id', 'id']);
            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
            $table->unique(['product_id', 'combination_key']);
            $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_attribute_id');
            $table->string('value', 60);
            $table->timestamps();

            $table->unique(['company_id', 'product_attribute_id', 'id'], 'attribute_values_company_attribute_id_unique');
            $table->unique(['product_attribute_id', 'value']);
            $table->foreign(['company_id', 'product_attribute_id'], 'attribute_values_attribute_foreign')->references(['company_id', 'id'])->on('product_attributes')->restrictOnDelete();
        });

        Schema::create('product_variant_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('product_attribute_id');
            $table->unsignedBigInteger('product_attribute_value_id');
            $table->timestamps();

            $table->unique(['product_variant_id', 'product_attribute_id'], 'variant_attributes_variant_attribute_unique');
            $table->foreign(['company_id', 'product_variant_id'], 'variant_attributes_variant_foreign')->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_attribute_id', 'product_attribute_value_id'], 'variant_attributes_value_foreign')->references(['company_id', 'product_attribute_id', 'id'])->on('product_attribute_values')->restrictOnDelete();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('disk', 40);
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 40);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'position']);
            $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['product_id', 'product_variant_id'])->references(['product_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_variant_id');
            $table->string('field', 30);
            $table->decimal('old_value', 15, 4)->nullable();
            $table->decimal('new_value', 15, 4)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('changed_at');

            $table->index(['product_variant_id', 'changed_at']);
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_changes');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variant_attributes');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('product_variants');
    }
};
