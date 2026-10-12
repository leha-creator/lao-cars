<?php

use App\Services\VisitorHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/*
 * Обезличенный идентификатор посетителя на одни сутки.
 */

beforeEach(function () {
    resetAnalytics();

    $this->hash = app(VisitorHash::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('gives one visitor the same hash within a day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
    $morning = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    Carbon::setTestNow(Carbon::parse('2026-10-07 23:59:00'));
    $evening = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    expect($morning)->toBe($evening)
        ->toMatch('/^[0-9a-f]{32}$/');
});

it('gives the same visitor another hash the next day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 23:59:00'));
    $today = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    Carbon::setTestNow(Carbon::parse('2026-10-08 00:01:00'));
    $tomorrow = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    // Обратная сторона обезличивания: за неделю человек посчитан столько
    // раз, сколько дней заходил.
    expect($tomorrow)->not->toBe($today);
});

it('tells visitors apart by the address and by the browser', function () {
    $base = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    expect($this->hash->for('203.0.113.8', 'Mozilla/5.0 Chrome'))->not->toBe($base)
        ->and($this->hash->for('203.0.113.7', 'Mozilla/5.0 Firefox'))->not->toBe($base);
});

it('keeps the salt through a cache flush', function () {
    // Сторож решения «соль не в кеше»: выкат делает `optimize:clear`,
    // и с солью в кеше уникальные молча удваивались бы в дни релизов.
    $before = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    Cache::flush();

    expect($this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome'))->toBe($before);
});

it('stores the salt under the date with a two-day lifetime and nowhere in the hash', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));

    $hash = $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    $redis = Redis::connection();
    $salt = $redis->get('analytics:salt:2026-10-07');

    expect($salt)->toMatch('/^[0-9a-f]{64}$/')
        ->and($redis->ttl('analytics:salt:2026-10-07'))->toBeGreaterThan(86_400)->toBeLessThanOrEqual(172_800)
        // Хеш зависит от соли: без неё он восстанавливался бы перебором
        // четырёх миллиардов адресов.
        ->and($hash)->toBe(substr(hash('sha256', $salt.'|203.0.113.7|Mozilla/5.0 Chrome'), 0, 32))
        ->and($hash)->not->toBe(substr(hash('sha256', '|203.0.113.7|Mozilla/5.0 Chrome'), 0, 32));
});

it('does not replace a salt another worker has already written', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00'));
    Redis::connection()->set('analytics:salt:2026-10-07', 'соль-первого-воркера');

    $this->hash->for('203.0.113.7', 'Mozilla/5.0 Chrome');

    expect(Redis::connection()->get('analytics:salt:2026-10-07'))->toBe('соль-первого-воркера');
});
