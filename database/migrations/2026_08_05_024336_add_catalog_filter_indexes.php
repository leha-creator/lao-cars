<?php

use App\Support\AttributeFilterIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Составные индексы, отложенные вехой 3.2 («подбираются в вехе 3.6
     * по фактическим запросам фильтра»), и индекс под фильтр
     * характеристик, форму которого предписал комментарий миграции
     * вехи 3.3.
     *
     * Все четыре создаются `DB::statement`, а не Schema builder-ом:
     * частичные индексы и индексы по выражению он не умеет. Образец —
     * `cars_homepage_index` из вехи 3.2.
     *
     * Предикат частичных индексов записан **тем же `IN`-списком**, каким
     * его строит скоуп `Car::available()`. Планировщик обязан вывести
     * предикат индекса из условия запроса: `status = 'in_stock'` он
     * из `status IN ('in_stock','on_order')` выводит, а `status <> 'sold'`
     * из `IN`-списка — уже нет.
     *
     * Одиночные индексы вехи 3.2 остаются на месте: новые перекрывают их
     * для публичного каталога, но `(status, created_at)` и одиночные
     * по `year`, `engine_type`, `price` обслуживают список админки,
     * который ходит по всем статусам, включая проданные.
     */
    public function up(): void
    {
        // Выдача по умолчанию и пагинация: порядок колонок повторяет
        // `ORDER BY created_at DESC, id DESC` из `CatalogFilter`.
        DB::statement(<<<'SQL'
            CREATE INDEX cars_available_created_index
                ON cars (created_at DESC, id DESC)
                WHERE status IN ('in_stock', 'on_order')
        SQL);

        // Самый частый фильтр вместе с умолчательной сортировкой.
        DB::statement(<<<'SQL'
            CREATE INDEX cars_available_brand_index
                ON cars (brand_id, created_at DESC)
                WHERE status IN ('in_stock', 'on_order')
        SQL);

        // Диапазон цены и сортировка по ней.
        DB::statement(<<<'SQL'
            CREATE INDEX cars_available_price_index
                ON cars (price, id)
                WHERE status IN ('in_stock', 'on_order')
        SQL);

        // Индекс по выражению, а не по колонке: `value` — `text`, а запись
        // b-tree ограничена 2704 байтами, то есть 1352 символами
        // кириллицы. Длина префикса берётся из константы, а не пишется
        // цифрой: `CatalogFilter` обязан строить условие тем же числом,
        // и расхождение стережёт тест по `pg_indexes`.
        DB::statement(sprintf(
            'CREATE INDEX car_attribute_values_filter_index ON car_attribute_values (car_attribute_id, left(value, %d))',
            AttributeFilterIndex::PREFIX_LENGTH,
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cars_available_created_index');
        DB::statement('DROP INDEX IF EXISTS cars_available_brand_index');
        DB::statement('DROP INDEX IF EXISTS cars_available_price_index');
        DB::statement('DROP INDEX IF EXISTS car_attribute_values_filter_index');
    }
};
