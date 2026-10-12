<?php

use App\Enums\AnalyticsPeriod;
use App\Enums\DeviceType;
use App\Models\Car;
use App\Models\PageView;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Carbon::setTestNow();
});

it('selects views inside the period', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:00:00'));

    PageView::factory()->create(['viewed_at' => '2026-10-07 13:30:00', 'path' => 'сейчас']);
    PageView::factory()->create(['viewed_at' => '2026-10-07 00:00:00', 'path' => 'полночь']);
    PageView::factory()->create(['viewed_at' => '2026-10-06 23:59:59', 'path' => 'вчера']);
    PageView::factory()->create(['viewed_at' => '2026-10-01 00:00:00', 'path' => 'неделя']);
    PageView::factory()->create(['viewed_at' => '2026-09-30 23:59:59', 'path' => 'за окном']);

    $paths = static fn (AnalyticsPeriod $period): array => PageView::within($period)
        ->orderBy('viewed_at')
        ->pluck('path')
        ->all();

    expect($paths(AnalyticsPeriod::LastHour))->toBe(['сейчас'])
        // Полночь входит в «сегодня», секунда до неё — уже нет.
        ->and($paths(AnalyticsPeriod::Today))->toBe(['полночь', 'сейчас'])
        ->and($paths(AnalyticsPeriod::Yesterday))->toBe(['вчера'])
        ->and($paths(AnalyticsPeriod::Last7Days))->toBe(['неделя', 'вчера', 'полночь', 'сейчас']);
});

it('keeps the view and drops the link when the car is deleted', function () {
    $car = Car::factory()->create();
    $view = PageView::factory()->forCar($car)->create();

    $car->delete();

    // Просмотр страницы остаётся в статистике недели — пропадает только
    // привязка к карточке.
    expect($view->fresh())->not->toBeNull()
        ->and($view->fresh()->car_id)->toBeNull();
});

it('casts the timestamp, the entry flag and the device', function () {
    $view = PageView::factory()->entry()->mobile()->create()->fresh();

    expect($view->viewed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($view->is_entry)->toBeTrue()
        ->and($view->device)->toBe(DeviceType::Mobile);
});

it('has no column an address or a user agent could be stored in', function () {
    // Сторож запрета из PHPDoc модели: статистика обезличена ровно до тех
    // пор, пока в таблице нет ни адреса, ни строки браузера.
    expect(Schema::getColumnListing('page_views'))->toEqualCanonicalizing([
        'id', 'viewed_at', 'path', 'route_name', 'car_id', 'visitor_hash', 'is_entry',
        'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'device',
    ]);
});

it('does not link a lead to a visitor', function () {
    // Со связью обезличенная статистика становится профилированием
    // конкретного человека.
    expect(Schema::hasColumn('leads', 'visitor_hash'))->toBeFalse();
});
