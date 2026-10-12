<?php

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Перенос данных миграцией вехи 4.13.
 *
 * Миграция, которая ничего не нашла, от миграции, отработавшей правильно,
 * снаружи не отличается ничем: обе завершаются успехом. Отсюда сторожа
 * на РЕЗУЛЬТАТ переноса, а не на факт прогона.
 *
 * Проверяется состояние базы ПОСЛЕ миграций — того самого прогона, который
 * делает `RefreshDatabase` перед набором. Отдельно прогонять миграции
 * внутри теста не нужно и вредно: `migrate:fresh` в транзакции теста
 * оставил бы соседям чужую схему.
 *
 * На тестовой базе шаг «прочитать настройку `services_page.notes`» читать
 * нечего, а шаг «привязать позиции» — нечего обновлять: это и есть тот
 * самый прогон на ПУСТОЙ базе, который план вехи назвал ловушкой. То, что
 * набор дошёл до этого файла, и означает, что он не упал.
 */

it('creates the service categories directory table with its index', function () {
    expect(Schema::hasTable('service_categories'))->toBeTrue()
        ->and(Schema::hasColumns('service_categories', ['name', 'slug', 'page', 'description', 'sort_order']))->toBeTrue();

    $indexes = collect(DB::select("select indexname from pg_indexes where tablename = 'service_categories'"))
        ->pluck('indexname');

    // Обе публичные страницы выбирают ровно по паре «страница + порядок».
    expect($indexes)->toContain('service_categories_page_sort_order_index');
});

it('seeds the five original categories with their anchors preserved', function () {
    // Слаги — это ЯКОРЯ блоков (`/services#tire-service`), и они уже
    // разошлись по документации, планам и прототипу: менять их молча
    // нельзя. Подчёркивание значения енама заменено дефисом ровно так,
    // как это делал удалённый `ServiceCategory::anchor()`.
    $categories = DB::table('service_categories')->orderBy('sort_order')->get();

    expect($categories->pluck('slug')->all())
        ->toBe(['maintenance', 'tire-service', 'detailing', 'extra', 'parts']);

    // Порядок кейсов старого енама сохранён, чтобы блоки на странице
    // не переставились после выкатки.
    expect($categories->pluck('sort_order')->all())->toBe([0, 1, 2, 3, 4]);

    // Принадлежность странице — колонка, а не имя категории.
    expect($categories->firstWhere('slug', 'parts')->page)->toBe('parts')
        ->and($categories->firstWhere('slug', 'maintenance')->page)->toBe('services');
});

it('replaces the enum column with a mandatory relation and the ordering indexes', function () {
    expect(Schema::hasColumn('services', 'category'))->toBeFalse()
        ->and(Schema::hasColumns('services', ['service_category_id', 'media_id', 'details', 'is_featured']))->toBeTrue();

    // Позиция без категории не выводится нигде — то есть существует,
    // но невидима. Отсюда `NOT NULL`, а не «nullable на всякий случай».
    $nullable = DB::selectOne(
        "select is_nullable from information_schema.columns where table_name = 'services' and column_name = 'service_category_id'",
    );

    expect($nullable->is_nullable)->toBe('NO');

    $indexes = collect(DB::select("select indexname from pg_indexes where tablename = 'services'"))
        ->pluck('indexname');

    expect($indexes)->toContain('services_service_category_id_sort_order_index')
        // Порядок выдачи вехи 4.13: акцентные, затем с фотографией,
        // затем остальные.
        ->toContain('services_service_category_id_is_featured_sort_order_index')
        ->not->toContain('services_category_sort_order_index');
});

it('removes the category notes setting the descriptions moved out of', function () {
    // Настройка удалена ЦЕЛИКОМ — из реестра, из формы, из сида и из базы.
    // Ключами объекта были значения енама, и с редактируемым справочником
    // такой объект превращается в мусор при первом же удалении категории.
    expect(DB::table('site_settings')->where('key', 'services_page.notes')->exists())->toBeFalse();
});

/*
 * Диапазон цен и уточнение к цене у автомобиля.
 */

it('adds the upper price bound to cars and services and the price note to cars', function () {
    expect(Schema::hasColumns('cars', ['price_max', 'price_note']))->toBeTrue()
        ->and(Schema::hasColumn('services', 'price_max'))->toBeTrue();
});

it('clears price notes left on positions without a price', function () {
    // На пустой тестовой базе миграции чистить нечего, поэтому очистка
    // вызывается на своих строках. Такие уточнения оставляло скрытое поле
    // формы: на редактировании оно не сохранялось, и очищенная цена
    // оставляла «от» в базе. Без очистки первая же правка позиции упёрлась
    // бы в новую валидацию цены по полю, которого никто не трогал.
    $orphan = Service::factory()->withoutPrice()->create();
    DB::table('services')->where('id', $orphan->id)->update(['price_note' => 'от']);
    $priced = Service::factory()->create(['price' => 1200, 'price_note' => 'за колесо']);

    $migration = require database_path('migrations/2026_09_22_120000_add_price_range_to_cars_and_services_tables.php');

    expect($migration->clearOrphanNotes())->toBe(1)
        ->and(DB::table('services')->where('id', $orphan->id)->value('price_note'))->toBeNull()
        ->and(DB::table('services')->where('id', $priced->id)->value('price_note'))->toBe('за колесо');
});

/*
 * Таблица просмотров собственной статистики посещений.
 */

it('creates the page views table with its indexes and drops it on rollback', function () {
    $indexes = static fn () => collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'page_views'"))
        ->pluck('indexdef', 'indexname');

    expect(Schema::hasTable('page_views'))->toBeTrue()
        // По периоду режут все отчёты и ночная чистка.
        ->and($indexes())->toHaveKey('page_views_viewed_at_index')
        ->and($indexes())->toHaveKey('page_views_car_id_index')
        // Индекс частичный: карточку автомобиля смотрит малая доля просмотров.
        ->and($indexes()['page_views_car_id_index'])->toContain('WHERE (car_id IS NOT NULL)');

    // DDL в PostgreSQL транзакционен: откат и повторный накат живут
    // в транзакции теста и соседям схему не меняют.
    $migration = require database_path('migrations/2026_10_07_140000_create_page_views_table.php');

    $migration->down();

    expect(Schema::hasTable('page_views'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('page_views'))->toBeTrue();
});
