<?php

/*
 * Адреса, по которым отдаётся всё загруженное медиа.
 *
 * Регрессия здесь тихая. Диск `public` строил URL от APP_URL, и это
 * выглядело правдоподобно ровно до тех пор, пока приложение поднималось
 * по адресу из APP_URL. Стоило запустить его на другом порту, как каждая
 * картинка на сайте уходила в ERR_CONNECTION_REFUSED — при целых файлах
 * на диске, живом симлинке public/storage и зелёных тестах: ни один из
 * них не смотрел на сам URL, а подстановка была консистентной.
 *
 * Отсюда две стороны одного правила. Разметка получает относительный
 * адрес, чтобы браузер разрешил его от origin текущей страницы, а
 * микроразметка — абсолютный, потому что относительный робот поисковика
 * не заберёт. Проверять нужно обе: фикс, который чинит только первую,
 * молча ломает вторую.
 */

use App\Filament\Resources\Cars\Pages\ListCars;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Media;
use App\Models\User;
use App\Services\CarStructuredData;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

it('builds media urls relative to the current origin', function () {
    expect(Storage::disk('public')->url('cars/img_1842.webp'))
        ->toBe('/storage/cars/img_1842.webp');
});

it('puts relative photo urls into the markup', function () {
    $car = Car::factory()->create();
    CarPhoto::factory()->for($car)->create(['path' => 'cars/img_1842.webp']);

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    expect($content)->toContain('src="/storage/cars/img_1842.webp"')
        // Абсолютный адрес в src означает возврат к прежней ошибке.
        ->and($content)->not->toContain('src="http://localhost/storage/');
});

it('keeps schema.org image urls absolute', function () {
    $car = Car::factory()->create();
    CarPhoto::factory()->for($car)->create(['path' => 'cars/img_1842.webp']);

    // `for()` отдаёт список узлов: первый — сам автомобиль, второй —
    // хлебные крошки.
    $vehicle = app(CarStructuredData::class)->for($car)[0];

    expect($vehicle['image'])->toBe([url('/storage/cars/img_1842.webp')])
        ->and($vehicle['image'][0])->toStartWith('http');
});

/*
 * Колонки админки живут по своему правилу, обратному правилу сайта.
 *
 * `ImageColumn::getImageUrl()` отдаёт состояние как есть, только если это
 * абсолютный URL; всё остальное он считает путём на диске и, не найдя там
 * файла, возвращает null — то есть `<img src="">`. Пустой src не роняет
 * страницу и не пишет в лог: колонка просто показывает пустую рамку,
 * и заметить это можно только глазами. Отсюда проверки ниже.
 */

it('renders admin thumbnails as absolute urls', function () {
    $this->actingAs(User::factory()->create());

    $car = Car::factory()->create();
    CarPhoto::factory()->for($car)->create(['thumb_path' => 'cars/thumbs/img_1842.webp']);
    $media = Media::factory()->create(['thumb_path' => 'media/thumbs/img_1.webp']);

    livewire(ListCars::class)
        ->assertOk()
        ->assertTableColumnStateSet(
            'mainPhoto.thumb_url',
            url('/storage/cars/thumbs/img_1842.webp'),
            $car,
        );

    livewire(ListMedia::class)
        ->assertOk()
        ->assertTableColumnStateSet(
            'thumb_url',
            url('/storage/media/thumbs/img_1.webp'),
            $media,
        );
});

it('never leaves an empty src in the cars list', function () {
    $this->actingAs(User::factory()->create());

    $car = Car::factory()->create();
    CarPhoto::factory()->for($car)->create();

    $html = livewire(ListCars::class)->assertOk()->html();

    // Симптом, ради которого написан тест: рамка на месте, картинки нет.
    expect($html)->not->toContain('<img alt="" src=""');
});
