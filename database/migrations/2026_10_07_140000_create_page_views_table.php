<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Сырые просмотры страниц собственной статистики посещений. Строки
     * живут неделю (`analytics.retention_days`) и пишутся пачками командой
     * `analytics:flush` из буфера Redis — на пути запроса посетителя
     * в эту таблицу не пишет никто.
     *
     * КОЛОНКИ IP ЗДЕСЬ НЕТ И НЕ ПОЯВЛЯЕТСЯ. Посетитель представлен
     * `visitor_hash` — хешем с суточной солью, которая живёт только в Redis
     * и через двое суток исчезает: после этого сопоставить хеш с адресом
     * нельзя ничем. Колонки User-Agent тоже нет — от него остаётся только
     * тип устройства.
     */
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table): void {
            $table->id();

            // Все отчёты режут по периоду — индекс обязателен, и по нему же
            // идёт ночная чистка.
            $table->timestamp('viewed_at')->index();

            // Без строки запроса: комбинаций фильтров каталога сотни,
            // и с ними «топ страниц» рассыпался бы на единичные строки.
            $table->string('path', 255);
            $table->string('route_name', 64)->nullable();

            // nullOnDelete: удалённый автомобиль не должен ни ронять запись
            // пачки, ни уносить с собой просмотры страницы за неделю.
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();

            $table->char('visitor_hash', 32);

            // Заход извне или прямой — а не переход внутри сайта.
            // Источники и рекламные метки считаются только по заходам.
            $table->boolean('is_entry')->default(false);

            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();

            $table->string('device', 16);

            // `created_at`/`updated_at` нет намеренно: строка не меняется,
            // а момент события — это `viewed_at`.
        });

        // Частичный индекс — Schema builder его не умеет. Карточку автомобиля
        // смотрит малая доля просмотров, и топ автомобилей не должен
        // перебирать остальные.
        DB::statement(<<<'SQL'
            CREATE INDEX page_views_car_id_index
                ON page_views (car_id, viewed_at)
                WHERE car_id IS NOT NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
