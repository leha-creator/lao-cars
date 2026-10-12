<?php

use App\Enums\AnalyticsPeriod;
use App\Models\Car;
use App\Models\User;
use App\Services\RequestCounter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/*
 * Middleware учёта: что куда попадает — и два инварианта, ради которых
 * учёт устроен именно так.
 */

const VISITOR_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

/**
 * Счётчик запросов за сегодня.
 *
 * @return array<string, int>
 */
function requestTotals(): array
{
    return app(RequestCounter::class)->totals(AnalyticsPeriod::Today);
}

/**
 * Просмотры, ждущие переноса в базу.
 *
 * @return list<array<string, mixed>>
 */
function pendingViews(): array
{
    return array_map(
        static fn (string $item): array => json_decode($item, true),
        Redis::connection()->lrange('analytics:views', 0, -1),
    );
}

beforeEach(function () {
    resetAnalytics();
    resetRateLimiters();

    // Умолчательный User-Agent тестового клиента — «Symfony». Просмотр
    // обязан считаться потому, что пришёл браузер, а не потому, что
    // «Symfony» случайно не попал в список роботов.
    $this->withHeader('User-Agent', VISITOR_BROWSER);
});

it('counts a page opened in a browser and buffers one view', function () {
    $this->get('/?utm_source=test')->assertOk();

    expect(requestTotals())->toMatchArray(['site_2xx' => 1, 'bot' => 0, 'panel' => 0])
        ->and(pendingViews())->toHaveCount(1)
        ->and(pendingViews()[0]['p'])->toBe('/')
        ->and(pendingViews()[0]['r'])->toBe('home')
        ->and(pendingViews()[0]['us'])->toBe('test');
});

it('attaches the car to a view of its card', function () {
    $car = Car::factory()->create();

    $this->get('/catalog/'.$car->slug)->assertOk();

    expect(pendingViews()[0]['c'])->toBe($car->id)
        ->and(pendingViews()[0]['r'])->toBe('catalog.show');
});

it('counts a missing page as a client error and not as a view', function () {
    $this->get('/catalog/no-such-car')->assertNotFound();
    $this->get('/no-such-page')->assertNotFound();

    expect(requestTotals())->toMatchArray(['site_4xx' => 2, 'site_2xx' => 0])
        ->and(pendingViews())->toBe([]);
});

it('keeps the health check and the panel apart from the site', function () {
    $this->get('/up')->assertOk();
    // Гость на панели получает редирект на вход — это всё равно панель.
    $this->get('/admin')->assertRedirect();

    expect(requestTotals())->toMatchArray(['system' => 1, 'panel' => 1, 'site_2xx' => 0, 'site_3xx' => 0])
        ->and(pendingViews())->toBe([]);
});

it('does not count the panel page an employee opens as a view', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertOk();

    expect(requestTotals()['panel'])->toBe(1)
        ->and(pendingViews())->toBe([]);
});

it('counts a robot apart and never as a view', function (string $userAgent) {
    $this->withHeader('User-Agent', $userAgent)->get('/')->assertOk();

    expect(requestTotals())->toMatchArray(['bot' => 1, 'site_2xx' => 0])
        ->and(pendingViews())->toBe([]);
})->with([
    'поисковый робот' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
    'curl' => ['curl/8.5.0'],
    'пустой User-Agent' => [''],
]);

it('does not count a signed-in employee browsing the site', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertOk();

    // Запрос был и в счётчике есть — нет только просмотра: менеджер,
    // проверяющий карточку после правки, не накручивает статистику.
    expect(requestTotals()['site_2xx'])->toBe(1)
        ->and(pendingViews())->toBe([]);
});

it('does not count a form submission as a view', function () {
    enableLeadForms();

    $this->post('/leads', [])->assertRedirect();

    expect(requestTotals()['site_3xx'])->toBe(1)
        ->and(pendingViews())->toBe([]);
});

it('does nothing when the statistics are switched off', function () {
    config(['analytics.enabled' => false]);

    $this->get('/')->assertOk();

    expect(array_sum(requestTotals()))->toBe(0)
        ->and(pendingViews())->toBe([]);
});

/*
 * Сторож решения «просмотры идут через буфер»: на счётчик запросов к базе
 * завязаны сторожа кеша настроек, главной и страницы 500. Учёт не имеет
 * права добавить к странице ни одного запроса.
 */
it('adds no database query to a public page', function () {
    $car = Car::factory()->create();
    warmSettingsCache();

    $measure = function () use ($car): array {
        return [
            countQueries(fn () => $this->get('/')->assertOk()),
            countQueries(fn () => $this->get('/catalog/'.$car->slug)->assertOk()),
        ];
    };

    $withAccounting = $measure();

    config(['analytics.enabled' => false]);

    $without = $measure();

    expect($withAccounting[0])->toBeGreaterThan(0)
        ->and($withAccounting)->toBe($without)
        // Просмотры при этом действительно записаны — тест сравнивает
        // не два выключенных состояния.
        ->and(pendingViews())->toHaveCount(2);
});

/*
 * Сторож решения «статистика не имеет права уронить запрос».
 *
 * Сбой устроен негодным регулярным выражением списка роботов: `preg_match`
 * даёт предупреждение, Laravel превращает его в исключение внутри
 * `terminate()`. Подменить сервис бросающим двойником нельзя — классы
 * учёта `final`, а сломать соединение Redis значит сломать и сессии,
 * то есть проверить не то.
 */
it('serves the page when the accounting itself fails', function () {
    config(['analytics.bot_pattern' => '/(незакрытая скобка/']);
    Log::spy();

    // Исключение из `terminate()` вылетело бы прямо из `get()`.
    $this->get('/')->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === '[Analytics] учёт запроса не выполнен'
                && $context['exception'] === ErrorException::class
                // Ни адреса, ни браузера посетителя в логе нет.
                && ! str_contains(json_encode($context), 'Chrome');
        })
        ->once();

    expect(pendingViews())->toBe([]);
});
