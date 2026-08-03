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
        Schema::create('car_attributes', function (Blueprint $table): void {
            $table->id();

            // Машинное имя и публичный контракт: по нему строится
            // GET-параметр фильтра каталога (веха 3.6) и обращения из
            // шаблона карточки (веха 4.3). Подпись правится свободно,
            // ключ — нет: его смена ломает сохранённые ссылки.
            $table->string('key')->unique();

            $table->string('label');
            $table->string('type');

            // Единица измерения осмысленна только у числовых
            // характеристик; на уровне БД это не запрещается — поле
            // прячет форма вехи 3.4.
            $table->string('unit')->nullable();

            // Группа в сетке карточки. Отдельной сущности нет: позиция
            // группы выводится из минимального sort_order её
            // характеристик, и третья таблица ради одного поля порядка
            // означала бы лишний джойн в карточке.
            $table->string('group')->nullable();

            // Допустимые значения типа select. Список живёт здесь, а не
            // собирается DISTINCT-ом по колонке значений: собранный
            // DISTINCT-ом список наполняется опечатками наполнителя —
            // «Кроссовер», «кроссовер» и «Кросовер» как три кузова.
            $table->jsonb('options')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Умолчания по частоте: характеристику заводят, чтобы
            // показать её в карточке, а в фильтр попадают единицы.
            $table->boolean('show_in_card')->default(true);
            $table->boolean('show_in_filter')->default(false);

            $table->timestamps();

            // Других индексов нет: справочник — десятки строк,
            // читается целиком.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_attributes');
    }
};
