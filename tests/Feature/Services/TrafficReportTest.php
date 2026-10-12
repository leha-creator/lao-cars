<?php

use App\Enums\AnalyticsPeriod;
use App\Enums\DeviceType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\Lead;
use App\Models\PageView;
use App\Services\TrafficReport;
use Illuminate\Support\Carbon;

/*
 * Отчёт о посещаемости: всё, что инфопанель показывает из `page_views`.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:00:00'));

    $this->report = app(TrafficReport::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * Итоги.
 */

it('counts a visitor who came back the next day twice', function () {
    // Прямой сторож решения про суточную соль. Хеш одинаковый нарочно:
    // даже если бы он совпал на два дня, посетитель считается по дням.
    PageView::factory()->byVisitor(str_repeat('a', 32))->create(['viewed_at' => '2026-10-06 10:00:00']);
    PageView::factory()->byVisitor(str_repeat('a', 32))->create(['viewed_at' => '2026-10-06 18:00:00']);
    PageView::factory()->byVisitor(str_repeat('a', 32))->create(['viewed_at' => '2026-10-07 09:00:00']);
    PageView::factory()->byVisitor(str_repeat('b', 32))->create(['viewed_at' => '2026-10-07 09:30:00']);

    $week = $this->report->summary(AnalyticsPeriod::Last7Days);

    expect($week['views'])->toBe(4)
        // «a» вчера, «a» сегодня и «b» сегодня — трое, а не двое.
        ->and($week['visitors'])->toBe(3)
        ->and($this->report->summary(AnalyticsPeriod::Yesterday)['visitors'])->toBe(1)
        ->and($this->report->summary(AnalyticsPeriod::Today)['visitors'])->toBe(2);
});

it('computes the conversion from leads of the same period', function () {
    PageView::factory()->count(8)->create(['viewed_at' => '2026-10-07 10:00:00']);
    Lead::factory()->create(['created_at' => '2026-10-07 11:00:00']);
    // Вчерашняя заявка в сегодняшнюю конверсию не входит.
    Lead::factory()->create(['created_at' => '2026-10-06 11:00:00']);

    $summary = $this->report->summary(AnalyticsPeriod::Today);

    expect($summary['leads'])->toBe(1)
        ->and($summary['visitors'])->toBe(8)
        ->and($summary['conversion'])->toBe(12.5);
});

it('has no conversion and no mobile share without visitors', function () {
    Lead::factory()->create(['created_at' => '2026-10-07 11:00:00']);

    $summary = $this->report->summary(AnalyticsPeriod::Today);

    // `null`, а не «0%» и не деление на ноль: заявка есть, посетителей
    // статистика не видела — доля не определена.
    expect($summary)->toBe([
        'views' => 0,
        'visitors' => 0,
        'leads' => 1,
        'conversion' => null,
        'mobile_share' => null,
    ]);
});

it('computes the share of mobile views', function () {
    PageView::factory()->count(3)->create();
    PageView::factory()->mobile()->create();

    expect($this->report->summary(AnalyticsPeriod::Today)['mobile_share'])->toBe(25.0);
});

it('puts a view at half past midnight Moscow time into today', function () {
    // По UTC это ещё 21:30 вчерашних суток.
    PageView::factory()->create(['viewed_at' => '2026-10-07 00:30:00']);
    PageView::factory()->create(['viewed_at' => '2026-10-06 23:30:00']);

    expect($this->report->summary(AnalyticsPeriod::Today)['views'])->toBe(1)
        ->and($this->report->summary(AnalyticsPeriod::Yesterday)['views'])->toBe(1);

    $byDay = collect($this->report->series(AnalyticsPeriod::Last7Days))
        ->mapWithKeys(fn (array $bucket): array => [$bucket['at']->format('m-d') => $bucket['views']]);

    expect($byDay['10-07'])->toBe(1)
        ->and($byDay['10-06'])->toBe(1);
});

/*
 * Ряд для графика.
 */

it('returns an hourly series with zeros in the empty buckets', function () {
    PageView::factory()->byVisitor(str_repeat('a', 32))->count(2)->create(['viewed_at' => '2026-10-07 09:15:00']);
    PageView::factory()->byVisitor(str_repeat('b', 32))->create(['viewed_at' => '2026-10-07 09:45:00']);
    PageView::factory()->create(['viewed_at' => '2026-10-07 13:05:00']);

    $series = $this->report->series(AnalyticsPeriod::Today);

    // 00:00 … 14:00 — пятнадцать корзин, как у счётчика запросов:
    // два графика на одной странице обязаны сойтись по оси.
    expect($series)->toHaveCount(15)
        ->and($series[9]['at']->format('H:i'))->toBe('09:00')
        ->and($series[9]['views'])->toBe(3)
        ->and($series[9]['visitors'])->toBe(2)
        ->and($series[10]['views'])->toBe(0)
        ->and($series[13]['views'])->toBe(1);
});

/*
 * Страницы.
 */

it('ranks pages by views', function () {
    PageView::factory()->count(3)->create(['path' => '/catalog']);
    PageView::factory()->byVisitor(str_repeat('a', 32))->count(2)->create(['path' => '/']);
    PageView::factory()->create(['path' => '/services']);
    PageView::factory()->create(['path' => '/вчера', 'viewed_at' => '2026-10-06 10:00:00']);

    expect($this->report->topPages(AnalyticsPeriod::Today))->toBe([
        ['path' => '/catalog', 'views' => 3, 'visitors' => 3],
        // Два просмотра одного посетителя — один посетитель.
        ['path' => '/', 'views' => 2, 'visitors' => 1],
        ['path' => '/services', 'views' => 1, 'visitors' => 1],
    ]);

    expect($this->report->topPages(AnalyticsPeriod::Today, 2))->toHaveCount(2);
});

/*
 * Источники и метки.
 */

it('lists the sources of entries only', function () {
    PageView::factory()->fromReferrer('yandex.ru')->count(3)->create();
    PageView::factory()->fromReferrer('t.me')->create();
    PageView::factory()->entry()->count(2)->create();
    // Переходы внутри сайта — не источник.
    PageView::factory()->count(5)->create();

    expect($this->report->sources(AnalyticsPeriod::Today))->toBe([
        ['host' => 'yandex.ru', 'label' => 'Яндекс', 'entries' => 3],
        ['host' => null, 'label' => 'Прямой заход', 'entries' => 2],
        ['host' => 't.me', 'label' => 'Telegram', 'entries' => 1],
    ]);
});

it('puts the direct entries after the named sources on a tie', function () {
    PageView::factory()->entry()->create();
    PageView::factory()->fromReferrer('yandex.ru')->create();
    PageView::factory()->fromReferrer('avito.ru')->create();

    // NULLS LAST задан явно: умолчание PostgreSQL зависит от направления.
    expect(array_column($this->report->sources(AnalyticsPeriod::Today), 'host'))
        ->toBe(['avito.ru', 'yandex.ru', null]);
});

it('groups the advertising tags', function () {
    PageView::factory()->entry()->count(2)->create(['utm_source' => 'yandex', 'utm_medium' => 'cpc', 'utm_campaign' => 'autumn']);
    PageView::factory()->entry()->create(['utm_source' => 'yandex', 'utm_medium' => 'cpc', 'utm_campaign' => null]);
    PageView::factory()->entry()->create(['utm_source' => 'vk']);
    PageView::factory()->entry()->count(4)->create();

    expect($this->report->campaigns(AnalyticsPeriod::Today))->toBe([
        ['source' => 'yandex', 'medium' => 'cpc', 'campaign' => 'autumn', 'entries' => 2],
        ['source' => 'vk', 'medium' => null, 'campaign' => null, 'entries' => 1],
        ['source' => 'yandex', 'medium' => 'cpc', 'campaign' => null, 'entries' => 1],
    ]);
});

it('returns no tags when none were used', function () {
    PageView::factory()->entry()->count(2)->create();

    // По пустому списку виджет не рендерит блок меток вовсе.
    expect($this->report->campaigns(AnalyticsPeriod::Today))->toBe([]);
});

/*
 * Автомобили и устройства.
 */

it('ranks cars with the brand and the model', function () {
    $zeekr = Car::factory()->for(Brand::factory()->create(['name' => 'Zeekr']))->create(['model' => '001', 'year' => 2024]);
    $lixiang = Car::factory()->for(Brand::factory()->create(['name' => 'Lixiang']))->create(['model' => 'L9', 'year' => 2025]);

    PageView::factory()->forCar($zeekr)->count(3)->create();
    PageView::factory()->forCar($lixiang)->create();
    PageView::factory()->count(4)->create();

    expect($this->report->topCars(AnalyticsPeriod::Today))->toBe([
        ['car_id' => $zeekr->id, 'title' => 'Zeekr 001 2024', 'views' => 3, 'visitors' => 3],
        ['car_id' => $lixiang->id, 'title' => 'Lixiang L9 2025', 'views' => 1, 'visitors' => 1],
    ]);
});

it('does not run a query per car', function () {
    $measure = function (int $cars): int {
        Car::factory()->count($cars)->create()->each(
            fn (Car $car) => PageView::factory()->forCar($car)->create(),
        );

        return countQueries(fn () => $this->report->topCars(AnalyticsPeriod::Today, 50));
    };

    $few = $measure(2);
    $many = $measure(10);

    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('leaves a deleted car out of the ranking', function () {
    $kept = Car::factory()->create();
    $deleted = Car::factory()->create();

    PageView::factory()->forCar($kept)->create();
    PageView::factory()->forCar($deleted)->count(5)->create();

    $deleted->delete();

    expect(array_column($this->report->topCars(AnalyticsPeriod::Today), 'car_id'))->toBe([$kept->id]);
});

it('splits the views by device, zero shares included', function () {
    PageView::factory()->count(3)->create();
    PageView::factory()->mobile()->create();

    expect($this->report->devices(AnalyticsPeriod::Today))->toBe([
        ['device' => DeviceType::Desktop, 'views' => 3, 'share' => 75.0],
        ['device' => DeviceType::Mobile, 'views' => 1, 'share' => 25.0],
        ['device' => DeviceType::Tablet, 'views' => 0, 'share' => 0.0],
    ]);
});

it('has no device shares without views', function () {
    expect(array_column($this->report->devices(AnalyticsPeriod::Today), 'share'))->toBe([null, null, null]);
});
