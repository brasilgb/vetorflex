<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->unique(['company_id', 'id']);
        });

        Schema::create('party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('party_id');
            $table->string('role', 30);
            $table->timestamps();

            $table->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('parties')->cascadeOnDelete();
            $table->unique(['party_id', 'role']);
            $table->index(['company_id', 'role', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_roles');

        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
