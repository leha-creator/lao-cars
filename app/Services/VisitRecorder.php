<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceType;
use App\Models\Car;
use App\Models\PageView;
use App\Support\BotDetector;
use App\Support\TrafficSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Учёт просмотров страниц: запись в буфер Redis и перенос буфера в базу.
 *
 * НА ПУТИ ЗАПРОСА НЕТ НИ ОДНОГО ЗАПРОСА К POSTGRESQL. `record()` делает
 * `RPUSH` компактной JSON-записи, а в `page_views` пачку раз в минуту
 * переносит `analytics:flush`. `INSERT` на каждый просмотр выглядит проще,
 * но добавлял бы запрос к базе каждой публичной странице — на счётчик
 * запросов завязаны сторожа кеша настроек, главной и страницы 500 (та
 * обязана обходиться без базы вовсе).
 *
 * `Request` принимает только этот сервис — граница та же, что у middleware,
 * которое его зовёт. В `flush()` и в `VisitorHash` уходят уже скаляры.
 */
final class VisitRecorder
{
    private const BUFFER_KEY = 'analytics:views';

    /** Замок предупреждения о переполнении: не чаще раза в 15 минут. */
    private const OVERFLOW_LOCK_KEY = 'analytics:views:overflow-warned';

    public function __construct(
        private readonly VisitorHash $visitors,
    ) {}

    /**
     * Считается ли ответ просмотром страницы.
     *
     * Правило ИСКЛЮЧАЮЩЕЕ, а не белый список имён маршрутов: новая
     * публичная страница начинает считаться сама. Белый список забыли бы
     * дополнить, и страница молча не попала бы в статистику.
     */
    public function shouldRecord(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        if (! str_starts_with(mb_strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return false;
        }

        if ($request->routeIs('filament.*')
            || in_array($request->path(), (array) config('analytics.ignore_paths'), strict: true)) {
            return false;
        }

        if (BotDetector::isBot($request->userAgent())) {
            return false;
        }

        // Браузер подгружает страницу заранее, человек её не видел.
        if (str_contains(mb_strtolower($request->headers->get('Sec-Purpose', '').' '.$request->headers->get('Purpose', '')), 'prefetch')) {
            return false;
        }

        // Вошедший сотрудник: менеджер, проверяющий карточку после правки,
        // иначе накручивал бы статистику сам себе.
        return ! auth()->check();
    }

    /**
     * Положить просмотр в буфер.
     */
    public function record(Request $request): void
    {
        $referer = $request->headers->get('Referer');
        $ownHost = $request->getHost();
        $car = $request->route('car');

        $entry = [
            't' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
            // Без строки запроса: комбинаций фильтров каталога сотни,
            // и с ними «топ страниц» рассыпался бы на единичные строки.
            'p' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
            'r' => $this->limited($request->route()?->getName(), 64),
            // Модель уже привязана маршрутом — лишнего запроса нет.
            'c' => $car instanceof Car ? $car->getKey() : null,
            'h' => $this->visitors->for((string) $request->ip(), $request->userAgent()),
            'e' => ! TrafficSource::isInternal($referer, $ownHost),
            's' => TrafficSource::host($referer, $ownHost),
            'us' => $this->limited($request->query('utm_source'), 100),
            'um' => $this->limited($request->query('utm_medium'), 100),
            'uc' => $this->limited($request->query('utm_campaign'), 100),
            'd' => DeviceType::fromUserAgent($request->userAgent())->value,
        ];

        $redis = Redis::connection();
        $limit = (int) config('analytics.buffer_limit');

        $length = (int) $redis->rpush(self::BUFFER_KEY, [json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        if ($length <= $limit) {
            return;
        }

        // Остановившийся планировщик не должен превращать статистику
        // в утечку памяти Redis: остаются самые свежие записи.
        $redis->ltrim(self::BUFFER_KEY, -$limit, -1);

        // При полном буфере сюда приходит КАЖДЫЙ просмотр. Без замка
        // предупреждение писалось бы на каждый запрос — и статистика сама
        // забивала бы диск, за которым следит.
        if ($redis->set(self::OVERFLOW_LOCK_KEY, '1', 'EX', 900, 'NX')) {
            Log::warning('[Analytics] буфер просмотров переполнен, старые записи отброшены', [
                'dropped' => $length - $limit,
                'limit' => $limit,
            ]);
        }
    }

    /**
     * Перенести пачку из буфера в `page_views`. Возвращает число строк.
     */
    public function flush(int $batch): int
    {
        $redis = Redis::connection();

        // Чтение и усечение — одной транзакцией Redis: просмотр,
        // записанный между ними, иначе был бы отрезан непрочитанным.
        [$items] = $redis->transaction(function ($transaction) use ($batch): void {
            $transaction->lrange(self::BUFFER_KEY, 0, $batch - 1);
            $transaction->ltrim(self::BUFFER_KEY, $batch, -1);
        });

        if (! is_array($items) || $items === []) {
            return 0;
        }

        try {
            $rows = $this->rows($items);

            if ($rows !== []) {
                PageView::insert($rows);
            }

            return count($rows);
        } catch (Throwable $e) {
            // База недоступна — пачка возвращается в голову буфера и уедет
            // следующим запуском: из Redis она уже вынута, и без возврата
            // минута статистики пропала бы молча.
            $redis->lpush(self::BUFFER_KEY, array_reverse($items));

            throw $e;
        }
    }

    /**
     * Сколько просмотров ждёт переноса.
     */
    public function pending(): int
    {
        return (int) Redis::connection()->llen(self::BUFFER_KEY);
    }

    /**
     * Записи буфера → строки таблицы.
     *
     * @param  list<string>  $items
     * @return list<array<string, mixed>>
     */
    private function rows(array $items): array
    {
        $entries = [];

        foreach ($items as $item) {
            $entry = json_decode($item, associative: true);

            // Нераспознанная запись пропускается: одна битая строка
            // не должна отменять пачку из тысячи целых.
            if (is_array($entry) && isset($entry['t'], $entry['p'], $entry['h'], $entry['d'])) {
                $entries[] = $entry;
            }
        }

        // Автомобиль могли удалить за минуту между просмотром и переносом.
        // Внешний ключ уронил бы всю пачку, поэтому id сверяются одним
        // запросом, отсутствующие обнуляются.
        $carIds = array_values(array_unique(array_filter(array_column($entries, 'c'))));
        $existing = $carIds === []
            ? []
            : DB::table('cars')->whereIn('id', $carIds)->pluck('id')->all();

        return array_map(static fn (array $entry): array => [
            'viewed_at' => $entry['t'],
            'path' => $entry['p'],
            'route_name' => $entry['r'] ?? null,
            'car_id' => in_array($entry['c'] ?? null, $existing, strict: false) ? $entry['c'] : null,
            'visitor_hash' => $entry['h'],
            'is_entry' => (bool) ($entry['e'] ?? false),
            'referrer_host' => $entry['s'] ?? null,
            'utm_source' => $entry['us'] ?? null,
            'utm_medium' => $entry['um'] ?? null,
            'utm_campaign' => $entry['uc'] ?? null,
            'device' => $entry['d'],
        ], $entries);
    }

    /**
     * Строка, обрезанная до длины колонки, — или `null`.
     *
     * Параметр запроса может прийти массивом (`?utm_source[]=x`): это
     * не метка, а мусор.
     */
    private function limited(mixed $value, int $length): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }
}
