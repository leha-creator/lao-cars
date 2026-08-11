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
 * Схема — ровно та, что генерирует `make:notifications-table`, и менять
 * её нельзя: колонки читает сам фреймворк.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
