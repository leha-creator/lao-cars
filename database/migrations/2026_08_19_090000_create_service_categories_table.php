<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник категорий услуг — веха 4.13.
 *
 * До него категорий было пять и жили они кейсами енама: добавить
 * шестую значило выкатить релиз. Образец сущности — `brands`.
 *
 * Наполнение таблицы и перенос описаний из настройки `services_page.notes`
 * делает следующая миграция: она же переводит `services.category` в связь,
 * и разносить перенос на две миграции значило бы получить состояние схемы,
 * в котором категории уже есть, а позиции на них ещё не смотрят.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();

            $table->string('name')->unique();
            $table->string('slug')->unique();

            // Публичная страница категории — `App\Enums\ServicePage`.
            // Хранится флагом, а не выводится из имени: имя правится
            // из админки, и отбор по нему опустошил бы посадочную
            // страницу запчастей молча.
            $table->string('page');

            // Абзац под названием категории. Пустое значение убирает
            // абзац, но не блок.
            $table->text('description')->nullable();

            // Порядок блоков на странице задаётся вручную.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Обе публичные страницы выбирают ровно по этой паре.
            $table->index(['page', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
