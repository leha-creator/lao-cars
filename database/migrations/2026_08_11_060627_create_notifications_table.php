<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Штатная таблица нотификаций Laravel (веха 4.7).
 *
 * Заведена ради колокольчика в панели Filament: `databaseNotifications()`
 * читает именно её, и без таблицы раздел падает запросом к несуществующему
 * отношению. До вехи 4.7 у трейта `Notifiable` в `User` не было ни одного
 * потребителя, поэтому таблицы и не было.
 *
 * Схема — та, что генерирует `make:notifications-table`, с ОДНОЙ правкой:
 * `data` объявлена `jsonb`, а не `text`.
 *
 * Правка вынужденная и ловится только на PostgreSQL. Колокольчик Filament
 * считает непрочитанные условием `data->>'format' = 'filament'`, а оператор
 * `->>` к типу `text` в PostgreSQL неприменим вовсе: запрос падает
 * `SQLSTATE[42883] operator does not exist: text ->> unknown`. На MySQL,
 * под который написана штатная миграция, `->>` работает и с текстовой
 * колонкой, поэтому в апстриме проблемы нет — и поэтому она не находится
 * поиском по документации Laravel.
 *
 * `jsonb`, а не `json`: по этой колонке идёт условие в каждом опросе
 * колокольчика (раз в 30 секунд на вкладку), а `jsonb` его и разбирает
 * быстрее, и индексируется. Каст модели (`data` → `array`) работает
 * с обоими типами одинаково.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
