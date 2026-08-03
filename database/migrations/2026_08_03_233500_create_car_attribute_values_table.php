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
        Schema::create('car_attribute_values', function (Blueprint $table): void {
            $table->id();

            // Значение не существует отдельно ни от автомобиля, ни от
            // характеристики: осиротевшее значение нечем отобразить —
            // нет ни подписи, ни типа, ни единицы измерения.
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_attribute_id')->constrained()->cascadeOnDelete();

            // text, а не string: свободная «Комплектация» бывает длиннее
            // 255 символов, а в PostgreSQL text и varchar по
            // производительности не различаются.
            $table->text('value');

            $table->timestamps();

            // Одна характеристика — одно значение на автомобиль. Порядок
            // колонок значим: префикс car_id покрывает выборку «все
            // характеристики этого авто» и заодно внешний ключ.
            $table->unique(['car_id', 'car_attribute_id']);

            // Покрытие второго внешнего ключа (PostgreSQL индекс под FK
            // сам не создаёт) и вход для фильтра каталога вехи 3.6.
            $table->index('car_attribute_id');

            // value в индексы не входит осознанно: запись b-tree
            // ограничена 2704 байтами, кириллица — 2 байта на символ,
            // то есть 1352 символа комплектации, вставленных копипастом,
            // роняют INSERT. Составной индекс под равенство фильтра
            // подбирается в вехе 3.6 и только с ограничением длины
            // выражением left(value, N).
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_attribute_values');
    }
};
