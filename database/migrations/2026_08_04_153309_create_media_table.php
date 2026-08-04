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
        Schema::create('media', function (Blueprint $table): void {
            $table->id();

            $table->string('disk')->default('public');

            // Уникальный: один файл — одна запись. Без этого ограничения
            // повторная загрузка того же файла плодит записи, а удаление
            // одной из них уносит с диска файл, на который ссылаются
            // остальные.
            $table->string('path')->unique();

            // `null` — обработка не удалась и оригинал сохранён как есть.
            $table->string('thumb_path')->nullable();

            // Человеческое имя для поиска: путь состоит из случайного
            // идентификатора, искать по нему нечего.
            $table->string('name');

            $table->string('alt')->nullable();
            $table->string('mime');

            // Байты уже после обработки, а не размер исходной загрузки.
            $table->unsignedInteger('size');

            $table->timestamps();

            // Сортировка библиотеки по умолчанию — новые сверху.
            $table->index('created_at');

            // Больше индексов не нужно: поиск идёт по `name` через ILIKE,
            // а библиотека — сотни строк, не миллионы.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
