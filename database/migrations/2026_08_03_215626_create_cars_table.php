<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table): void {
            $table->id();

            // restrictOnDelete: удаление марки, за которой числятся авто,
            // должно падать, а не уносить часть каталога каскадом.
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();

            $table->string('model');
            $table->string('slug')->unique();

            $table->unsignedSmallInteger('year');
            $table->string('engine_type');
            $table->decimal('engine_volume', 3, 1)->nullable();
            $table->unsignedSmallInteger('engine_power')->nullable();
            $table->string('drive');

            // null — «Новый»: у авто под заказ пробега нет.
            $table->unsignedInteger('mileage')->nullable();

            // Целые рубли. null — «цена по запросу»; ноль означал бы
            // «бесплатно», а не «уточняйте».
            $table->unsignedBigInteger('price')->nullable();

            $table->string('status')->default('in_stock');
            $table->boolean('show_on_homepage')->default(false);

            $table->text('history')->nullable();
            $table->text('description')->nullable();

            // SEO карточки — веха 4.3; пустые значения замещаются
            // настройками по умолчанию из site_settings.
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Список каталога: «сначала доступные, новые сверху».
            $table->index(['status', 'created_at']);

            // Одиночные индексы под фильтры раздела 3.2 ТЗ. Составные
            // подбираются в вехе 3.6 по фактическим запросам фильтра.
            //
            // brand_id индексируется явно: PostgreSQL, в отличие от MySQL,
            // не создаёт индекс под внешний ключ сам, а фильтр по марке —
            // первый в списке фильтров каталога.
            $table->index('brand_id');
            $table->index('year');
            $table->index('engine_type');
            $table->index('price');
        });

        // Подборка на главной — единицы записей из всего каталога.
        // Частичный индекс покрывает ровно их и не растёт вместе с
        // таблицей; Schema builder частичные индексы не умеет.
        DB::statement('CREATE INDEX cars_homepage_index ON cars (sort_order) WHERE show_on_homepage');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Индекс уходит вместе с таблицей, но явный DROP оставлен на
        // случай частичного отката: DROP INDEX до DROP TABLE безопасен.
        DB::statement('DROP INDEX IF EXISTS cars_homepage_index');

        Schema::dropIfExists('cars');
    }
};
