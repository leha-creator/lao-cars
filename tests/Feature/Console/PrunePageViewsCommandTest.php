<?php

use App\Models\PageView;
use App\Services\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
 * `analytics:prune` — ночная чистка просмотров: срок и потолок строк.
 */

beforeEach(function () {
    resetAnalytics();

    Carbon::setTestNow(Carbon::parse('2026-10-07 03:10:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('deletes views older than the retention boundary to the second', function () {
    PageView::factory()->create(['viewed_at' => '2026-10-07 03:00:00', 'path' => '/сегодня']);
    // Ровно семь дней назад в 00:00 — ещё внутри срока.
    PageView::factory()->create(['viewed_at' => '2026-09-30 00:00:00', 'path' => '/граница']);
    PageView::factory()->create(['viewed_at' => '2026-09-29 23:59:59', 'path' => '/секундой-раньше']);
    PageView::factory()->create(['viewed_at' => '2026-09-01 12:00:00', 'path' => '/давно']);

    $this->artisan('analytics:prune')
        ->expectsOutputToContain('Удалено по сроку: 2, сверх потолка: 0, осталось: 2.')
        ->assertSuccessful();

    expect(PageView::query()->orderBy('viewed_at')->pluck('path')->all())
        ->toBe(['/граница', '/сегодня']);
});

it('takes the retention period from the config', function () {
    config(['analytics.retention_days' => 2]);

    PageView::factory()->create(['viewed_at' => '2026-10-05 00:00:00', 'path' => '/остаётся']);
    PageView::factory()->create(['viewed_at' => '2026-10-04 23:59:59', 'path' => '/удаляется']);

    $this->artisan('analytics:prune')->assertSuccessful();

    expect(PageView::query()->pluck('path')->all())->toBe(['/остаётся']);
});

it('deletes the oldest views above the row ceiling and warns', function () {
    config(['analytics.max_rows' => 3]);
    Log::spy();

    foreach (['01:00', '02:00', '03:00', '00:30', '02:30'] as $time) {
        PageView::factory()->create(['viewed_at' => '2026-10-06 '.$time.':00', 'path' => '/'.$time]);
    }

    $this->artisan('analytics:prune')
        ->expectsOutputToContain('Удалено по сроку: 0, сверх потолка: 2, осталось: 3.')
        ->assertSuccessful();

    // Уходят самые старые по времени просмотра, а не по порядку вставки.
    expect(PageView::query()->orderBy('viewed_at')->pluck('path')->all())
        ->toBe(['/02:00', '/02:30', '/03:00']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === '[Analytics] превышен потолок строк'
            && $context['excess'] === 2);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('[Analytics] чистка завершена', ['deleted_by_age' => 0, 'deleted_by_limit' => 2, 'remaining' => 3]);
});

it('counts the ceiling after the expired rows are gone', function () {
    config(['analytics.max_rows' => 2]);

    PageView::factory()->count(2)->create(['viewed_at' => '2026-09-01 12:00:00']);
    PageView::factory()->count(2)->create(['viewed_at' => '2026-10-06 12:00:00']);

    // Четыре строки при потолке в две — но две из них и так уходят
    // по сроку, и сверх потолка удалять нечего.
    $this->artisan('analytics:prune')
        ->expectsOutputToContain('Удалено по сроку: 2, сверх потолка: 0, осталось: 2.')
        ->assertSuccessful();
});

it('deletes nothing on a dry run and reports the same numbers', function () {
    config(['analytics.max_rows' => 2]);

    PageView::factory()->count(2)->create(['viewed_at' => '2026-09-01 12:00:00']);
    PageView::factory()->count(3)->create(['viewed_at' => '2026-10-06 12:00:00']);

    $this->artisan('analytics:prune', ['--dry-run' => true])
        ->expectsOutputToContain('По сроку будет удалено: 2, сверх потолка: 1, останется: 2.')
        ->assertSuccessful();

    expect(PageView::query()->count())->toBe(5)
        // Пробный прогон — не чистка: отметка пульса не ставится, иначе
        // блок служб считал бы ночную чистку состоявшейся.
        ->and(app(SchedulerHeartbeat::class)->lastBeat(SchedulerHeartbeat::PRUNE))->toBeNull();
});

it('leaves a heartbeat after a real run', function () {
    $this->artisan('analytics:prune')->assertSuccessful();

    expect(app(SchedulerHeartbeat::class)->lastBeat(SchedulerHeartbeat::PRUNE)?->format('Y-m-d H:i'))
        ->toBe('2026-10-07 03:10');
});
