<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Пульс задач планировщика: когда команда последний раз отработала.
 *
 * Статистика посещений целиком зависит от `schedule:run` в cron: встал
 * планировщик — просмотры копятся в буфере Redis, а инфопанель показывает
 * вчерашние числа как сегодняшние. Поэтому команды оставляют отметку
 * времени, а блок служб показывает её возраст.
 *
 * Отметки лежат в `Redis::connection()`, а не в кеше, — по той же причине,
 * что счётчик запросов: кеш чистит каждый выкат.
 */
final class SchedulerHeartbeat
{
    public const FLUSH = 'flush';

    public const PRUNE = 'prune';

    private const KEY_PREFIX = 'ops:heartbeat:';

    /**
     * Отметить, что команда только что отработала.
     *
     * Срок жизни отметки заведомо больше периода запуска команды: исчезнувший
     * ключ и есть сигнал «давно не запускалась».
     */
    public function beat(string $name, int $ttlSeconds): void
    {
        Redis::connection()->set(self::KEY_PREFIX.$name, (string) CarbonImmutable::now()->getTimestamp(), 'EX', $ttlSeconds);
    }

    /**
     * Когда команда отработала последний раз — или `null`, если отметки нет.
     */
    public function lastBeat(string $name): ?CarbonImmutable
    {
        $value = Redis::connection()->get(self::KEY_PREFIX.$name);

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) $value, (string) config('app.timezone'));
    }
}
