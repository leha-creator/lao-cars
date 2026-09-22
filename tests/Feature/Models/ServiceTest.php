<?php

use App\Enums\ServicePage;
use App\Models\Lead;
use App\Models\Media;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\Log;

it('belongs to a category from the directory', function () {
    // До вехи 4.13 категория была кейсом енама в колонке `category`,
    // и тест проверял каст. Проверяемое свойство то же — позиция знает
    // свою категорию, — сменился только её носитель.
    //
    // Внешний ключ у связи задан ЯВНО: Eloquent вывел бы его из имени
    // метода (`category_id`), а колонка называется `service_category_id`,
    // и связь молча отдавала бы `null` вместо ошибки (правило `RULES.md`).
    $category = ServiceCategory::factory()->create(['name' => 'Проверочная категория']);

    $service = Service::factory()->inCategory($category)->create();

    expect($service->refresh()->category)->not->toBeNull()
        ->and($service->category->name)->toBe('Проверочная категория');
});

it('filters services by category', function () {
    $parts = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts']);
    $detailing = ServiceCategory::factory()->create(['slug' => 'test-detailing']);
    $empty = ServiceCategory::factory()->create(['slug' => 'test-empty']);

    Service::factory()->count(3)->inCategory($parts)->create();
    Service::factory()->count(2)->inCategory($detailing)->create();

    expect(Service::inCategory($parts)->count())->toBe(3)
        ->and(Service::inCategory($detailing)->count())->toBe(2)
        ->and(Service::inCategory($empty)->count())->toBe(0);
});

it('hides unpublished services from public queries', function () {
    $category = ServiceCategory::factory()->create();

    Service::factory()->count(2)->inCategory($category)->create();
    Service::factory()->inCategory($category)->unpublished()->create();

    expect(Service::published()->count())->toBe(2)
        ->and(Service::count())->toBe(3);
});

it('orders featured first, then positions with a photo, then by sort order and title', function () {
    // Порядок выдачи вехи 4.13 — прямая формулировка заказчика («сначала
    // показывались пункты с фото, а потом остальные»). `sort_order`
    // авторитетен ВНУТРИ группы: у акцентной позиции он самый большой,
    // и без групп она встала бы последней.
    $category = ServiceCategory::factory()->create();

    Service::factory()->inCategory($category)->create(['title' => 'Четвёртый', 'sort_order' => 1]);
    Service::factory()->inCategory($category)->create(['title' => 'Третий', 'sort_order' => 0]);
    Service::factory()->inCategory($category)->withPhoto()->create(['title' => 'Второй', 'sort_order' => 5]);
    Service::factory()->inCategory($category)->featured()->create(['title' => 'Первый', 'sort_order' => 9]);

    expect(Service::ordered()->pluck('title')->all())->toBe(['Первый', 'Второй', 'Третий', 'Четвёртый']);
});

it('builds a transliterated slug from a russian title', function () {
    $service = Service::factory()->create(['title' => 'Шиномонтаж легкового автомобиля']);

    // Кириллица должна давать читаемый латинский slug, а не пустую строку.
    expect($service->slug)->toBe('sinomontaz-legkovogo-avtomobilia');
});

it('allows a null price meaning "on request"', function () {
    $service = Service::factory()->withoutPrice()->create();

    expect($service->price)->toBeNull()
        ->and($service->price_note)->toBeNull();
});

it('formats the price line with the note on the side it belongs to', function (?int $price, ?int $max, ?string $note, string $label) {
    // Формат общий с карточкой автомобиля (`HasPriceRange`). Предлог
    // встаёт перед суммой, остальное — после; у диапазона предлоги свои,
    // и «от» из уточнения к нему не приписывается — иначе «от от …».
    //
    // Пробелы внутри суммы, перед «₽» и после предлога — неразрывные:
    // на «Сервисе» цена вдвое крупнее текста и переносится, и обычный
    // пробел разорвал бы «35 000» на две строки. Обычных пробелов два —
    // перед «до» и перед припиской; ровно там переносу и место.
    $service = Service::factory()->create(['price' => $price, 'price_max' => $max, 'price_note' => $note]);

    expect($service->priceLabel())->toBe($label);
})->with([
    'точная' => [3200, null, null, "3\u{a0}200\u{a0}₽"],
    'от' => [6500, null, 'от', "от\u{a0}6\u{a0}500\u{a0}₽"],
    'От с заглавной' => [6500, null, 'От', "От\u{a0}6\u{a0}500\u{a0}₽"],
    'суффикс' => [1200, null, 'за колесо', "1\u{a0}200\u{a0}₽ за колесо"],
    'диапазон' => [9000, 15000, null, "от\u{a0}9\u{a0}000 до\u{a0}15\u{a0}000\u{a0}₽"],
    'диапазон с суффиксом' => [4500, 6000, 'за сезон', "от\u{a0}4\u{a0}500 до\u{a0}6\u{a0}000\u{a0}₽ за сезон"],
    'диапазон и «от»' => [9000, 15000, 'от', "от\u{a0}9\u{a0}000 до\u{a0}15\u{a0}000\u{a0}₽"],
    'диапазон и «до»' => [9000, 15000, 'до', "от\u{a0}9\u{a0}000 до\u{a0}15\u{a0}000\u{a0}₽"],
    'без цены' => [null, null, null, 'по запросу'],
]);

it('drops an upper bound that is not above the price and warns about it', function (?int $price, int $max) {
    // Форма такого не пропустит, а сид, импорт или tinker — могут.
    Log::spy();

    $service = Service::factory()->create(['price' => $price, 'price_max' => $max]);

    expect($service->refresh()->price_max)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'диапазон снят')
            && $context['price_max'] === $max);
})->with([
    'равна цене' => [9000, 9000],
    'меньше цены' => [9000, 5000],
    'без цены' => [null, 9000],
]);

it('exposes the photo url and null without a photo', function () {
    // Аксессор отдаёт `url`, а не `thumb_url`: превью заведомо мельче
    // карточки во всю ширину контента, а `ImageProcessor` ограничивает
    // ширину сверху, но не апскейлит.
    $media = Media::factory()->create();

    $withPhoto = Service::factory()->withPhoto($media)->create();
    $withoutPhoto = Service::factory()->create();

    expect($withPhoto->imageUrl)->toBe($media->url)
        ->and($withoutPhoto->imageUrl)->toBeNull();
});

it('separates categories of the services page from those of the parts landing', function () {
    // До вехи 4.13 запчасти исключал сам енам (`isParts()`), и тест
    // сторожил состав его кейсов. Теперь принадлежность странице — колонка,
    // и сторожить надо ровно её: имя категории правится из админки,
    // а флаг нет.
    ServiceCategory::factory()->count(2)->create();
    ServiceCategory::factory()->parts()->create(['slug' => 'test-parts']);

    $services = ServiceCategory::onPage(ServicePage::Services)->pluck('slug');

    expect($services)->not->toContain('test-parts')
        ->and(ServiceCategory::onPage(ServicePage::Parts)->pluck('slug'))->toContain('test-parts');
});

it('collects leads submitted for a service', function () {
    $service = Service::factory()->create();

    Lead::factory()->forService($service)->create();

    expect($service->leads)->toHaveCount(1);
});
