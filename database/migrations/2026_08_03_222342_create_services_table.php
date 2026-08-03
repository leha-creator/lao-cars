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
        Schema::create('services', function (Blueprint $table): void {
            $table->id();

            // Единая сущность на все категории, включая запчасти:
            // посадочной странице запчастей нужны ровно эти поля.
            // Модель Part появится вместе с витриной (артикулы,
            // наличие, фильтры), которой в MVP нет — см. DESCRIPTION.md.
            $table->string('category');

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Целые рубли; null — «цена по запросу».
            $table->unsignedBigInteger('price')->nullable();

            // Уточнение к цене: «от», «за колесо», «за 1 л». Без него
            // прайс автосервиса не описывается одной суммой.
            $table->string('price_note')->nullable();

            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Страница автосервиса собирается блоками по категориям,
            // каждый блок — выборка «категория + порядок».
            $table->index(['category', 'sort_order']);
            $table->index('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
