<?php

use App\Enums\DeviceType;
use App\Models\Car;
use App\Models\PageView;
use App\Models\User;
use App\Services\VisitRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/*
 * Учёт просмотров: что считается просмотром, что уходит в буфер
 * и как буфер переезжает в базу.
 */

const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

/**
 * Запрос посетителя с привязанным маршрутом — как его видит middleware.
 *
 * @param  array<string, string>  $headers
 */
function visitRequest(string $uri, array $headers = [], string $routeName = 'home', ?Car $car = null, string $method = 'GET'): Request
{
    $server = ['HTTP_USER_AGENT' => BROWSER, 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_HOST' => 'laocars.ru'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create('https://laocars.ru'.$uri, $method, server: $server);

    $route = (new Route($method, $car === null ? '/' : 'catalog/{car}', static fn () => null))->name($routeName);
    $route->bind($request);

    if ($car !== null) {
        $route->setParameter('car', $car);
    }

    $request->setRouteResolver(static fn () => $route);

    return $request;
}

function htmlResponse(int $status = 200, string $type = 'text/html; charset=UTF-8'): Response
{
    return new Response('<html></html>', $status, ['Content-Type' => $type]);
}

/**
 * Записи буфера, разобранные из JSON.
 *
 * @return list<array<string, mixed>>
 */
function bufferedViews(): array
{
    return array_map(
        static fn (string $item): array => json_decode($item, true),
        Redis::connection()->lrange('analytics:views', 0, -1),
    );
}

beforeEach(function () {
    resetAnalytics();

    $this->recorder = app(VisitRecorder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * Что считается просмотром.
 */

it('counts an html page opened by a guest in a browser', function () {
    expect($this->recorder->shouldRecord(visitRequest('/'), htmlResponse()))->toBeTrue();
});

it('does not count what is not a page view', function (Closure $request, Closure $response) {
    expect($this->recorder->shouldRecord($request(), $response()))->toBeFalse();
})->with([
    'не GET' => [fn () => visitRequest('/leads', method: 'POST'), fn () => htmlResponse()],
    'не найдено' => [fn () => visitRequest('/'), fn () => htmlResponse(404)],
    'редирект' => [fn () => visitRequest('/'), fn () => htmlResponse(302)],
    'не HTML' => [fn () => visitRequest('/'), fn () => htmlResponse(200, 'application/json')],
    'панель' => [fn () => visitRequest('/admin', routeName: 'filament.admin.pages.dashboard'), fn () => htmlResponse()],
    'служебный адрес' => [fn () => visitRequest('/up', routeName: 'up'), fn () => htmlResponse()],
    'робот' => [fn () => visitRequest('/', ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0)']), fn () => htmlResponse()],
    'пустой User-Agent' => [fn () => visitRequest('/', ['User-Agent' => '']), fn () => htmlResponse()],
    'предзагрузка Chrome' => [fn () => visitRequest('/', ['Sec-Purpose' => 'prefetch;prerender']), fn () => htmlResponse()],
    'предзагрузка Firefox' => [fn () => visitRequest('/', ['Purpose' => 'prefetch']), fn () => htmlResponse()],
]);

it('does not count a signed-in employee', function () {
    // Менеджер, проверяющий карточку после правки, иначе накручивал бы
    // статистику сам себе.
    $this->actingAs(User::factory()->create());

    expect($this->recorder->shouldRecord(visitRequest('/'), htmlResponse()))->toBeFalse();
});

/*
 * Что уходит в буфер.
 */

it('buffers the view without the query string and with the utm tags apart', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:35:20'));

    $this->recorder->record(visitRequest(
        '/catalog?brand=zeekr&price_to=5000000&utm_source=yandex&utm_medium=cpc&utm_campaign=autumn',
        routeName: 'catalog.index',
    ));

    $view = bufferedViews()[0];

    expect(bufferedViews())->toHaveCount(1)
        ->and($view['t'])->toBe('2026-10-07 14:35:20')
        // Комбинаций фильтров сотни — в адрес они не попадают.
        ->and($view['p'])->toBe('/catalog')
        ->and($view['r'])->toBe('catalog.index')
        ->and($view['us'])->toBe('yandex')
        ->and($view['um'])->toBe('cpc')
        ->and($view['uc'])->toBe('autumn')
        ->and($view['d'])->toBe('desktop')
        ->and($view['c'])->toBeNull();
});

it('stores the home page as a slash', function () {
    $this->recorder->record(visitRequest('/'));

    expect(bufferedViews()[0]['p'])->toBe('/');
});

it('cuts over-long values down to the column size', function () {
    $this->recorder->record(visitRequest('/'.str_repeat('a', 400).'?utm_source='.str_repeat('б', 150)));

    $view = bufferedViews()[0];

    // Кириллица режется по символам, а не по байтам: колонка — varchar(100).
    expect(mb_strlen($view['p']))->toBe(255)
        ->and(mb_strlen($view['us']))->toBe(100);
});

it('ignores a utm tag that is not a string', function () {
    $this->recorder->record(visitRequest('/?utm_source[]=x&utm_medium='));

    expect(bufferedViews()[0]['us'])->toBeNull()
        ->and(bufferedViews()[0]['um'])->toBeNull();
});

it('tells an entry from a navigation inside the site', function () {
    $this->recorder->record(visitRequest('/'));
    $this->recorder->record(visitRequest('/', ['Referer' => 'https://yandex.ru/search/?text=лао+карс']));
    $this->recorder->record(visitRequest('/catalog', ['Referer' => 'https://laocars.ru/']));

    [$direct, $search, $internal] = bufferedViews();

    expect($direct['e'])->toBeTrue()->and($direct['s'])->toBeNull()
        ->and($search['e'])->toBeTrue()->and($search['s'])->toBe('yandex.ru')
        // Переход внутри сайта — не заход: в источники он не попадёт.
        ->and($internal['e'])->toBeFalse()->and($internal['s'])->toBeNull();
});

it('takes the car from the route binding and the device from the user agent', function () {
    $car = Car::factory()->create();

    $this->recorder->record(visitRequest(
        '/catalog/'.$car->slug,
        ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'],
        'catalog.show',
        $car,
    ));

    expect(bufferedViews()[0]['c'])->toBe($car->id)
        ->and(bufferedViews()[0]['d'])->toBe('mobile');
});

it('keeps the buffer under the limit and warns once', function () {
    config(['analytics.buffer_limit' => 3]);
    Log::spy();

    foreach (['/1', '/2', '/3', '/4', '/5'] as $uri) {
        $this->recorder->record(visitRequest($uri));
    }

    // Остаются самые свежие: остановившийся планировщик не должен
    // превращать статистику в утечку памяти Redis.
    expect(array_column(bufferedViews(), 'p'))->toBe(['/3', '/4', '/5']);

    // Переполнений было два, предупреждение одно: при полном буфере сюда
    // приходит каждый просмотр, и лог иначе рос бы вместе с трафиком.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, '[Analytics] буфер просмотров переполнен')
            && $context['dropped'] === 1);
});

/*
 * Перенос буфера в базу.
 */

it('moves a batch into the table and empties the buffer', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:35:20'));

    $this->recorder->record(visitRequest('/?utm_source=test', ['Referer' => 'https://t.me/laocars']));
    $this->recorder->record(visitRequest('/catalog', ['Referer' => 'https://laocars.ru/'], 'catalog.index'));

    expect($this->recorder->flush(100))->toBe(2)
        ->and($this->recorder->pending())->toBe(0)
        ->and($this->recorder->flush(100))->toBe(0);

    $views = PageView::query()->orderBy('id')->get();

    expect($views)->toHaveCount(2)
        ->and($views[0]->path)->toBe('/')
        ->and($views[0]->viewed_at->format('Y-m-d H:i:s'))->toBe('2026-10-07 14:35:20')
        ->and($views[0]->is_entry)->toBeTrue()
        ->and($views[0]->referrer_host)->toBe('t.me')
        ->and($views[0]->utm_source)->toBe('test')
        ->and($views[0]->device)->toBe(DeviceType::Desktop)
        ->and($views[1]->route_name)->toBe('catalog.index')
        ->and($views[1]->is_entry)->toBeFalse()
        // Один браузер с одного адреса — один посетитель.
        ->and($views[1]->visitor_hash)->toBe($views[0]->visitor_hash);
});

it('moves no more than the batch at a time', function () {
    foreach (['/1', '/2', '/3'] as $uri) {
        $this->recorder->record(visitRequest($uri));
    }

    expect($this->recorder->flush(2))->toBe(2)
        ->and($this->recorder->pending())->toBe(1)
        ->and(PageView::query()->orderBy('id')->pluck('path')->all())->toBe(['/1', '/2']);
});

it('uses a single insert for the whole batch', function () {
    foreach (['/1', '/2', '/3', '/4'] as $uri) {
        $this->recorder->record(visitRequest($uri));
    }

    $queries = countQueries(fn () => $this->recorder->flush(100));

    // Ровно один: автомобилей в пачке нет, сверять нечего.
    expect($queries)->toBe(1);
});

it('does not lose the batch to a car deleted in the meantime', function () {
    $kept = Car::factory()->create();
    $deleted = Car::factory()->create();

    $this->recorder->record(visitRequest('/catalog/'.$kept->slug, routeName: 'catalog.show', car: $kept));
    $this->recorder->record(visitRequest('/catalog/'.$deleted->slug, routeName: 'catalog.show', car: $deleted));

    $deleted->delete();

    // Внешний ключ уронил бы всю пачку вместе с просмотром живой карточки.
    expect($this->recorder->flush(100))->toBe(2)
        ->and(PageView::query()->orderBy('id')->pluck('car_id')->all())->toBe([$kept->id, null]);
});

it('skips a corrupted buffer entry instead of failing the batch', function () {
    $this->recorder->record(visitRequest('/1'));
    Redis::connection()->rpush('analytics:views', ['не json', '{"t":"2026-10-07 10:00:00"}']);
    $this->recorder->record(visitRequest('/2'));

    expect($this->recorder->flush(100))->toBe(2)
        ->and(PageView::query()->orderBy('id')->pluck('path')->all())->toBe(['/1', '/2']);
});

it('returns the batch to the buffer when the database rejects it', function () {
    $this->recorder->record(visitRequest('/1'));
    // Хеш длиннее колонки `char(32)` — вставка всей пачки падает.
    Redis::connection()->rpush('analytics:views', [json_encode([
        't' => '2026-10-07 10:00:00', 'p' => '/2', 'h' => str_repeat('f', 64), 'd' => 'desktop',
    ])]);

    $this->recorder->record(visitRequest('/3'));

    // Вложенная транзакция — savepoint: ошибка PostgreSQL иначе оборвала бы
    // транзакцию самого теста, и проверить после неё было бы нечего.
    expect(fn () => DB::transaction(fn () => $this->recorder->flush(100)))->toThrow(QueryException::class);

    // Из Redis пачка уже вынута — без возврата минута статистики пропала
    // бы молча. Порядок записей сохранён.
    expect($this->recorder->pending())->toBe(3)
        ->and(array_column(bufferedViews(), 'p'))->toBe(['/1', '/2', '/3'])
        ->and(PageView::query()->count())->toBe(0);
});

it('never stores the address or the user agent', function () {
    $this->recorder->record(visitRequest('/', ['Referer' => 'https://yandex.ru/']));

    $raw = Redis::connection()->lrange('analytics:views', 0, -1)[0];

    // В буфере — та же запись, что уедет в базу: ни там, ни там.
    expect($raw)->not->toContain('203.0.113.7')
        ->not->toContain('Chrome')
        ->not->toContain('Mozilla');

    $this->recorder->flush(100);

    $row = json_encode(PageView::query()->first()->getAttributes());

    expect($row)->not->toContain('203.0.113.7')
        ->not->toContain('Chrome')
        ->not->toContain('Mozilla');
});
