<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name', 60);
            $table->boolean('allows_fraction')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'parent_id', 'name']);
            $table->foreign(['company_id', 'parent_id'])->references(['company_id', 'id'])->on('product_categories')->restrictOnDelete();
        });

        Schema::create('product_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('internal_code', 60)->nullable();
            $table->unsignedBigInteger('product_category_id')->nullable();
            $table->unsignedBigInteger('product_brand_id')->nullable();
            $table->unsignedBigInteger('unit_of_measure_id');
            $table->string('manufacturer_reference', 100)->nullable();
            $table->boolean('tracks_inventory')->default(true);
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('merchandise_origin', 1)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'internal_code']);
            $table->index(['company_id', 'is_active', 'name']);
            $table->index(['company_id', 'name']);
            $table->foreign(['company_id', 'product_category_id'])->references(['company_id', 'id'])->on('product_categories')->restrictOnDelete();
            $table->foreign(['company_id', 'product_brand_id'])->references(['company_id', 'id'])->on('product_brands')->restrictOnDelete();
            $table->foreign(['company_id', 'unit_of_measure_id'])->references(['company_id', 'id'])->on('units_of_measure')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_brands');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('units_of_measure');
    }
};
