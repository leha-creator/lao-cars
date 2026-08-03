<?php

/*
 * Проверка того, что инфраструктура каркаса собрана правильно.
 *
 * Эти тесты намеренно ходят в живые PostgreSQL и Redis. Подмена драйверов
 * (SQLite в памяти, array-кеш, `Queue::fake()`) сделала бы их зелёными ровно в
 * том случае, ради которого они и написаны, — когда контейнеры не подняты или
 * приложение смотрит не туда. Если `docker compose down` не роняет этот файл,
 * значит тесты проверяют не то, что заявлено.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

it('connects to postgresql with a live pdo connection', function () {
    $pdo = DB::connection()->getPdo();

    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('laocars_testing')
        ->and($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toStartWith('17.');

    // Запрос, а не только факт соединения: connection может быть ленивым.
    expect(DB::scalar('select 1'))->toBe(1);
});

it('reaches redis', function () {
    expect((string) Redis::ping())->toContain('PONG');
});

it('stores cache entries in redis', function () {
    expect(config('cache.default'))->toBe('redis');

    Cache::put('scaffold:cache-probe', 'сохранено', 60);

    expect(Cache::get('scaffold:cache-probe'))->toBe('сохранено');

    Cache::forget('scaffold:cache-probe');

    expect(Cache::get('scaffold:cache-probe'))->toBeNull();
});

it('pushes jobs to the redis queue instead of running them inline', function () {
    // Без Queue::fake(): проверяется именно то, что задача уходит в очередь и
    // ждёт воркера. При QUEUE_CONNECTION=sync размер очереди остался бы нулевым,
    // а уведомление о заявке выполнилось бы прямо в цикле запроса.
    expect(config('queue.default'))->toBe('redis');

    $before = Queue::size();

    dispatch(function (): void {
        // Тело не важно: проверяется транспорт, а не полезная нагрузка.
    });

    expect(Queue::size())->toBe($before + 1);

    // Очередь переживает тест: RefreshDatabase откатывает только БД.
    Queue::pop()?->delete();

    expect(Queue::size())->toBe($before);
});
