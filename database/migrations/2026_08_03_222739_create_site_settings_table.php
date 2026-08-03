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
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();

            // Ключ вида «<группа>.<имя>»: contacts.phone, home.ticker.
            $table->string('key')->unique();

            // jsonb, а не текст: значения разнородны — от строки телефона
            // до массива из четырёх карточек преимуществ. Колонка на
            // каждый блок означала бы миграцию на каждую правку макета.
            $table->jsonb('value')->nullable();

            // Группа продублирована колонкой ради выборки блока целиком
            // и группировки полей на странице настроек (веха 3.5).
            $table->string('group')->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
