<?php

use App\Models\PageView;
use App\Services\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/*
 * `analytics:flush` — перенос просмотров из буфера Redis в базу.
 */

/**
 * Положить в буфер просмотры в том виде, в каком их пишет `VisitRecorder`.
 */
function bufferViews(int $count): void
{
    $items = [];

    for ($i = 1; $i <= $count; $i++) {
        $items[] = json_encode([
            't' => '2026-10-07 12:00:00', 'p' => '/page-'.$i, 'r' => null, 'c' => null,
            'h' => str_repeat('a', 32), 'e' => true, 's' => null,
            'us' => null, 'um' => null, 'uc' => null, 'd' => 'desktop',
        ]);
    }

    Redis::connection()->rpush('analytics:views', $items);
}

beforeEach(function () {
    resetAnalytics();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('moves the whole buffer in batches', function () {
    config(['analytics.flush_batch' => 2]);
    bufferViews(5);

    $this->artisan('analytics:flush')
        ->expectsOutputToContain('Перенесено просмотров: 5.')
        ->assertSuccessful();

    // Три пачки по две — буфер вычерпан за один запуск, а не за три минуты.
    expect(PageView::query()->count())->toBe(5)
        ->and(Redis::connection()->llen('analytics:views'))->toBe(0);
});

it('leaves a heartbeat even when the buffer is empty', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:30'));

    $this->artisan('analytics:flush')->assertSuccessful();

    // Отметка — про «планировщик жив», а не про «были посетители».
    expect(app(SchedulerHeartbeat::class)->lastBeat(SchedulerHeartbeat::FLUSH)?->format('Y-m-d H:i:s'))
        ->toBe('2026-10-07 12:00:30')
        ->and(Redis::connection()->ttl('ops:heartbeat:flush'))->toBeGreaterThan(0);
});

it('logs the result only when something was moved', function () {
    Log::spy();

    $this->artisan('analytics:flush')->assertSuccessful();

    // Пустой запуск молчит: иначе 1440 строк в сутки.
    Log::shouldNotHaveReceived('info');

    bufferViews(3);

    $this->artisan('analytics:flush')->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('[Analytics] буфер сброшен', ['rows' => 3]);
});

it('is scheduled together with the nightly cleanup', function () {
    $events = collect(app(Schedule::class)->events())
        ->keyBy(fn (Event $event): string => str_contains($event->command, 'analytics:flush') ? 'flush'
            : (str_contains($event->command, 'analytics:prune') ? 'prune' : $event->command));

    expect($events)->toHaveKeys(['flush', 'prune'])
        ->and($events['flush']->expression)->toBe('* * * * *')
        ->and($events['flush']->withoutOverlapping)->toBeTrue()
        // 03:10 — до бэкапа базы в 03:30: дамп не возит удаляемое.
        ->and($events['prune']->expression)->toBe('10 3 * * *');
});
