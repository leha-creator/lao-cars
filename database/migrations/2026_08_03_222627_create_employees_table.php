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
        // Карточки команды на странице «О компании» (раздел 3.4 ТЗ).
        // К таблице users отношения не имеет: это контент сайта,
        // а не учётные записи админки.
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('position');
            $table->text('bio')->nullable();
            $table->string('photo_path')->nullable();

            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
