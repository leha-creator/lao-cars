<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фото сотрудников и отзывов переезжают на медиабиблиотеку.
 *
 * `ARCHITECTURE.md` держал строку временно — «до тех пор сотрудники и
 * отзывы хранят путь к файлу строкой». Данных для переноса нет: колонка
 * `photo_path` не получала значения ни разу (фабрики кладут `null`, сиды
 * её не трогают, интерфейса для неё не существовало).
 *
 * `nullOnDelete()`, а не каскад: изображение, удалённое из библиотеки,
 * оставляет карточку сотрудника без фото, а не роняет страницу
 * «О компании» битой ссылкой.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const array TABLES = ['employees', 'reviews'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Имя таблицы задано явно не потому, что инфлектор ошибётся
                // (проверено: `Str::plural('media')` возвращает `media`),
                // а потому что читателю миграции не должно приходиться это
                // проверять.
                $table->foreignId('media_id')
                    ->nullable()
                    ->constrained(table: 'media')
                    ->nullOnDelete();

                $table->dropColumn('photo_path');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Колонка возвращается, данные — нет; их и не было. Выполнять откат
     * на базе, которая старше этого утверждения, без `pg_dump` не стоит.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Снимает внешний ключ вместе с колонкой; голый
                // `dropColumn()` при живом ключе — не тот идиом.
                $table->dropConstrainedForeignId('media_id');

                $table->string('photo_path')->nullable();
            });
        }
    }
};
