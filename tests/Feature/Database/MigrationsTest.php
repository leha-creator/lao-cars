<?php

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
