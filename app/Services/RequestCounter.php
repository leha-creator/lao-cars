<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnalyticsPeriod;
use App\Support\BotDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Счётчик запросов, дошедших до PHP, — часовыми корзинами в Redis.
 *
 * Ключ `analytics:requests:{YmdH}` — хеш с полями из `FIELDS`. Часовые
 * корзины дают все периоды фильтра инфопанели без единой таблицы.
 *
 * ПОЧЕМУ `Redis::connection()`, А НЕ `Cache::`. `deploy.sh` на каждом выкате
 * делает `optimize:clear`, а он чистит хранилище кеша целиком: счётчик,
 * положенный в кеш, обнулялся бы в дни релизов. По той же причине здесь же
 * живут соль посетителя и буфер просмотров.
 *
 * ПОЧЕМУ НЕ ЛОГ NGINX. Файл принадлежит `www-data:adm`, а пул работает
 * от `laocars`; статика в нём всё равно выключена; плюс ротация. Считается
 * то, что дошло до приложения, — это и есть нагрузка на него. Запросы
 * за время `artisan down` сюда не попадают: их отбивает middleware режима
 * обслуживания раньше, и окно выката не рисует ложный всплеск 5xx.
 *
 * Сервис не логирует и исключений не ловит: это делает middleware
 * `RecordRequestMetrics`, иначе одно событие писалось бы дважды.
 */
final class RequestCounter
{
    /**
     * Поля корзины.
     *
     * `panel` выделен намеренно: колокольчик опрашивает панель каждые
     * 30 секунд с каждой открытой вкладки, и без разделения три вкладки
     * сотрудников выглядели бы как посещаемость.
     */
    public const FIELDS = ['site_2xx', 'site_3xx', 'site_4xx', 'site_5xx', 'panel', 'system', 'bot'];

    private const KEY_PREFIX = 'analytics:requests:';

    /**
     * Учесть один запрос в корзине текущего часа.
     */
    public function hit(string $field): void
    {
        $key = $this->key(CarbonImmutable::now());
        $redis = Redis::connection();

        $redis->hincrby($key, $field, 1);

        // Срок продлевается каждым запросом — дешевле, чем узнавать,
        // выставлен ли он уже. Корзина всё равно исчезнет через
        // `counter_ttl_days` после последнего запроса своего часа.
        $redis->expire($key, (int) config('analytics.counter_ttl_days') * 86_400);
    }

    /**
     * В какое поле корзины идёт запрос.
     *
     * Принимает скаляры, а не `Request`: правило слоёв проекта — сервис
     * про HTTP не знает. Разбирает запрос middleware.
     *
     * Порядок проверок значим: панель и служебные адреса определяются
     * по пути РАНЬШЕ робота — мониторинг ходит на `/up` без браузерного
     * User-Agent и иначе раздувал бы поле `bot`.
     *
     * @param  string  $path  путь без ведущего слеша, как отдаёт `$request->path()`
     */
    public function classify(string $path, ?string $userAgent, int $status): string
    {
        // `livewire` — префиксом без слеша: Livewire 4 добавляет к сегменту
        // хеш (`livewire-xxxx/update`), и опрос виджетов панели идёт туда.
        if ($path === 'admin' || str_starts_with($path, 'admin/') || str_starts_with($path, 'livewire')) {
            return 'panel';
        }

        if (in_array($path, (array) config('analytics.ignore_paths'), strict: true)) {
            return 'system';
        }

        if (BotDetector::isBot($userAgent)) {
            return 'bot';
        }

        return match (true) {
            $status >= 500 => 'site_5xx',
            $status >= 400 => 'site_4xx',
            $status >= 300 => 'site_3xx',
            default => 'site_2xx',
        };
    }

    /**
     * Ряд за период: корзина на час или на день, с нулями на пустых местах.
     *
     * Точность — час. «Последний час» в 14:35 покажет корзины 13:00 и 14:00
     * целиком: запросы хранятся суммой за час, и резать её по минутам нечем.
     *
     * @return list<array{at: CarbonImmutable, site_2xx: int, site_3xx: int, site_4xx: int, site_5xx: int, panel: int, system: int, bot: int}>
     */
    public function series(AnalyticsPeriod $period): array
    {
        $buckets = $period->buckets();

        if ($buckets === []) {
            return [];
        }

        $daily = $period->granularity() === 'day';
        [, $to] = $period->range();

        // Часы читаются одним конвейером: неделя — это 168 ключей,
        // и по запросу на ключ инфопанель стоила бы 168 обращений к Redis.
        $hours = [];
        $cursor = $buckets[0];
        $last = $daily ? $to->startOfHour() : end($buckets);

        while ($cursor->lessThanOrEqualTo($last)) {
            $hours[] = $cursor;
            $cursor = $cursor->addHour();
        }

        $rows = Redis::connection()->pipeline(function ($pipe) use ($hours): void {
            foreach ($hours as $hour) {
                $pipe->hgetall($this->key($hour));
            }
        });

        $series = [];

        foreach ($buckets as $bucket) {
            $series[$bucket->format($daily ? 'Ymd' : 'YmdH')] = ['at' => $bucket] + array_fill_keys(self::FIELDS, 0);
        }

        foreach ($hours as $index => $hour) {
            // Часы складываются в дни по часовому поясу приложения:
            // ключ корзины пишется в нём же (`key()`).
            $slot = $hour->format($daily ? 'Ymd' : 'YmdH');

            foreach (self::FIELDS as $field) {
                $series[$slot][$field] += (int) ($rows[$index][$field] ?? 0);
            }
        }

        return array_values($series);
    }

    /**
     * Суммы по полям за период.
     *
     * @return array{site_2xx: int, site_3xx: int, site_4xx: int, site_5xx: int, panel: int, system: int, bot: int}
     */
    public function totals(AnalyticsPeriod $period): array
    {
        $totals = array_fill_keys(self::FIELDS, 0);

        foreach ($this->series($period) as $bucket) {
            foreach (self::FIELDS as $field) {
                $totals[$field] += $bucket[$field];
            }
        }

        return $totals;
    }

    /**
     * Ключ часовой корзины.
     *
     * Единственное место, знающее формат: запись и чтение через разные
     * выражения разошлись бы в формате даты, и счётчик молча показывал бы
     * нули при живых данных в Redis.
     */
    private function key(CarbonImmutable $at): string
    {
        return self::KEY_PREFIX.$at->setTimezone((string) config('app.timezone'))->format('YmdH');
    }
}
