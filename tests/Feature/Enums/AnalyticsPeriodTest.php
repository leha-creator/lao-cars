<?php

use App\Enums\AnalyticsPeriod;
use Illuminate\Support\Carbon;

/*
 * Границы периода фильтра инфопанели.
 *
 * Время зафиксировано: без этого тест «сегодня начинается в полночь»
 * проверял бы разное в зависимости от того, когда его запустили.
 */

afterEach(function () {
    Carbon::setTestNow();
});

it('computes the bounds of every period', function (AnalyticsPeriod $period, string $from, string $to) {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:35:20'));

    [$start, $end] = $period->range();

    expect($start->format('Y-m-d H:i:s'))->toBe($from)
        ->and($end->format('Y-m-d H:i:s'))->toBe($to);
})->with([
    'последний час' => [AnalyticsPeriod::LastHour, '2026-10-07 13:35:20', '2026-10-07 14:35:20'],
    'сегодня' => [AnalyticsPeriod::Today, '2026-10-07 00:00:00', '2026-10-07 14:35:20'],
    'вчера' => [AnalyticsPeriod::Yesterday, '2026-10-06 00:00:00', '2026-10-06 23:59:59'],
    '24 часа' => [AnalyticsPeriod::Last24Hours, '2026-10-06 14:35:20', '2026-10-07 14:35:20'],
    // Сегодня и шесть предыдущих дней — окно, которое оставляет чистка.
    '7 дней' => [AnalyticsPeriod::Last7Days, '2026-10-01 00:00:00', '2026-10-07 14:35:20'],
]);

it('crosses midnight without losing the previous day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 00:20:00'));

    // Двадцать минут первого: «последний час» начался вчера,
    // а «сегодня» длится всего двадцать минут.
    expect(AnalyticsPeriod::LastHour->range()[0]->format('Y-m-d H:i'))->toBe('2026-10-06 23:20')
        ->and(AnalyticsPeriod::Today->range()[0]->format('Y-m-d H:i'))->toBe('2026-10-07 00:00')
        ->and(AnalyticsPeriod::Yesterday->range()[1]->format('Y-m-d H:i'))->toBe('2026-10-06 23:59');
});

it('counts the day in the application timezone, not in UTC', function () {
    // 00:30 по Москве — это ещё 21:30 предыдущих суток по UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-06 21:30:00', 'UTC'));

    expect(AnalyticsPeriod::Today->range()[0]->format('Y-m-d H:i'))->toBe('2026-10-07 00:00');
});

it('uses daily buckets only for the week', function () {
    expect(AnalyticsPeriod::Last7Days->granularity())->toBe('day')
        ->and(AnalyticsPeriod::LastHour->granularity())->toBe('hour')
        ->and(AnalyticsPeriod::Today->granularity())->toBe('hour')
        ->and(AnalyticsPeriod::Yesterday->granularity())->toBe('hour')
        ->and(AnalyticsPeriod::Last24Hours->granularity())->toBe('hour');
});

it('lists the chart buckets without gaps', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:35:20'));

    $format = static fn (AnalyticsPeriod $period, string $pattern): array => array_map(
        static fn ($bucket): string => $bucket->format($pattern),
        $period->buckets(),
    );

    expect($format(AnalyticsPeriod::Last7Days, 'm-d'))
        ->toBe(['10-01', '10-02', '10-03', '10-04', '10-05', '10-06', '10-07'])
        // Час, в котором период начался, и текущий — оба в ряду.
        ->and($format(AnalyticsPeriod::LastHour, 'H:i'))->toBe(['13:00', '14:00'])
        ->and($format(AnalyticsPeriod::Today, 'H:i'))->toHaveCount(15)
        ->and($format(AnalyticsPeriod::Yesterday, 'H:i'))->toHaveCount(24)
        ->and($format(AnalyticsPeriod::Last24Hours, 'H:i'))->toHaveCount(25);
});
