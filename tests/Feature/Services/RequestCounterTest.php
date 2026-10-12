<?php

use App\Enums\AnalyticsPeriod;
use App\Services\RequestCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/*
 * Счётчик запросов в Redis: часовые корзины, семь полей.
 */

beforeEach(function () {
    resetAnalytics();

    $this->counter = app(RequestCounter::class);
    $this->browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sorts a request into one of the seven fields', function (string $path, ?string $userAgent, int $status, string $field) {
    $userAgent = $userAgent === 'browser' ? $this->browser : $userAgent;

    expect($this->counter->classify($path, $userAgent, $status))->toBe($field);
})->with([
    'главная' => ['/', 'browser', 200, 'site_2xx'],
    'редирект' => ['catalog', 'browser', 302, 'site_3xx'],
    'не найдено' => ['catalog/net-takoy', 'browser', 404, 'site_4xx'],
    'лимит заявок' => ['leads', 'browser', 429, 'site_4xx'],
    'ошибка сервера' => ['catalog', 'browser', 500, 'site_5xx'],
    'панель' => ['admin', 'browser', 200, 'panel'],
    'раздел панели' => ['admin/cars', 'browser', 200, 'panel'],
    'опрос Livewire' => ['livewire-1a2b3c/update', 'browser', 200, 'panel'],
    // Панель остаётся панелью и с ошибкой: её 500 — не ошибка сайта.
    'ошибка в панели' => ['admin/cars', 'browser', 500, 'panel'],
    'проверка живости' => ['up', 'browser', 200, 'system'],
    // Мониторинг ходит на /up без браузерного User-Agent — это всё ещё
    // служебный запрос, а не робот.
    'проверка живости скриптом' => ['up', 'curl/8.5.0', 200, 'system'],
    'робот' => ['catalog', 'Mozilla/5.0 (compatible; YandexBot/3.0)', 200, 'bot'],
    'пустой User-Agent' => ['/', null, 200, 'bot'],
    // Страница, чей адрес лишь начинается на «admin», — не панель.
    'похожий адрес' => ['administration', 'browser', 404, 'site_4xx'],
]);

it('covers every declared field', function () {
    // Сторож списка: поле, в которое ничего не классифицируется, —
    // это столбец графика, который всегда пуст.
    $fields = [
        $this->counter->classify('/', $this->browser, 200),
        $this->counter->classify('/', $this->browser, 301),
        $this->counter->classify('/', $this->browser, 404),
        $this->counter->classify('/', $this->browser, 503),
        $this->counter->classify('admin', $this->browser, 200),
        $this->counter->classify('up', $this->browser, 200),
        $this->counter->classify('/', 'curl/8.5.0', 200),
    ];

    expect($fields)->toEqualCanonicalizing(RequestCounter::FIELDS);
});

it('counts hits into the bucket of the current hour and sets its lifetime', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:35:00'));

    $this->counter->hit('site_2xx');
    $this->counter->hit('site_2xx');
    $this->counter->hit('bot');

    $redis = Redis::connection();

    expect($redis->hgetall('analytics:requests:2026100714'))->toBe(['site_2xx' => '2', 'bot' => '1']);

    // Без срока корзины копились бы вечно: чистить их некому.
    $ttl = $redis->ttl('analytics:requests:2026100714');

    expect($ttl)->toBeGreaterThan(7 * 86_400)
        ->toBeLessThanOrEqual(8 * 86_400);
});

it('returns an hourly series with zeros in the empty buckets', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:10:00'));
    $this->counter->hit('site_2xx');
    $this->counter->hit('site_4xx');

    Carbon::setTestNow(Carbon::parse('2026-10-07 12:40:00'));
    $this->counter->hit('site_2xx');

    $series = $this->counter->series(AnalyticsPeriod::Today);

    // 00:00 … 12:00 — тринадцать корзин, пропусков нет.
    expect($series)->toHaveCount(13)
        ->and($series[10]['at']->format('H:i'))->toBe('10:00')
        ->and($series[10]['site_2xx'])->toBe(1)
        ->and($series[10]['site_4xx'])->toBe(1)
        ->and($series[11]['site_2xx'])->toBe(0)
        ->and($series[12]['site_2xx'])->toBe(1)
        // Нетронутое поле — ноль, а не отсутствующий ключ.
        ->and($series[12]['site_5xx'])->toBe(0);
});

it('folds hours into days in the application timezone', function () {
    // 23:30 и 00:30 по Москве — разные сутки, хотя по UTC это один день.
    Carbon::setTestNow(Carbon::parse('2026-10-05 23:30:00'));
    $this->counter->hit('site_2xx');

    Carbon::setTestNow(Carbon::parse('2026-10-06 00:30:00'));
    $this->counter->hit('site_2xx');
    $this->counter->hit('site_2xx');

    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
    $this->counter->hit('site_5xx');

    $series = $this->counter->series(AnalyticsPeriod::Last7Days);
    $byDay = collect($series)->mapWithKeys(fn (array $bucket): array => [$bucket['at']->format('m-d') => $bucket]);

    expect($series)->toHaveCount(7)
        ->and($byDay['10-05']['site_2xx'])->toBe(1)
        ->and($byDay['10-06']['site_2xx'])->toBe(2)
        ->and($byDay['10-07']['site_5xx'])->toBe(1)
        ->and($byDay['10-04']['site_2xx'])->toBe(0);

    expect($this->counter->totals(AnalyticsPeriod::Last7Days))->toMatchArray([
        'site_2xx' => 3,
        'site_5xx' => 1,
        'bot' => 0,
    ]);
});

it('leaves out hits older than the period', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 15:00:00'));
    $this->counter->hit('site_2xx');

    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00'));
    $this->counter->hit('site_2xx');

    expect($this->counter->totals(AnalyticsPeriod::Today)['site_2xx'])->toBe(1)
        ->and($this->counter->totals(AnalyticsPeriod::Yesterday)['site_2xx'])->toBe(1)
        ->and($this->counter->totals(AnalyticsPeriod::Last7Days)['site_2xx'])->toBe(2);
});

it('survives a cache flush', function () {
    // Сторож решения «ключи учёта живут не в кеше»: выкат чистит кеш
    // целиком, и счётчик в нём обнулялся бы в дни релизов.
    $this->counter->hit('site_2xx');

    Cache::flush();

    expect($this->counter->totals(AnalyticsPeriod::Today)['site_2xx'])->toBe(1);
});

it('is reset by the test helper without touching the queue', function () {
    $this->counter->hit('site_2xx');
    Redis::connection()->set('ops:heartbeat:flush', '1');
    Redis::connection()->set('queues:probe', '1');

    resetAnalytics();

    expect($this->counter->totals(AnalyticsPeriod::Today)['site_2xx'])->toBe(0)
        ->and(Redis::connection()->get('ops:heartbeat:flush'))->toBeNull()
        // Очередь живёт в той же базе Redis — помощник обязан её не задеть.
        ->and(Redis::connection()->get('queues:probe'))->toBe('1');

    Redis::connection()->del('queues:probe');
});
