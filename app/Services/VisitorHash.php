<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Обезличенный идентификатор посетителя на одни сутки.
 *
 * `sha256(соль | IP | User-Agent)`, обрезанный до 32 hex-символов. Соль —
 * 32 случайных байта, своя на каждый день; живёт только в Redis и исчезает
 * через 48 часов. После этого сопоставить хеш с адресом нельзя ничем —
 * в том числе нам самим. Сырой IP не хранится нигде.
 *
 * ОБРАТНАЯ СТОРОНА, которую обязаны повторять подпись виджета и справка:
 * посетитель уникален ВНУТРИ ДНЯ. Назавтра у того же человека другой хеш,
 * и за неделю он посчитан столько раз, сколько дней заходил.
 *
 * Соль лежит в `Redis::connection()`, а не в кеше: кеш чистит каждый выкат
 * (`optimize:clear`), и «уникальные за день» молча удваивались бы в дни
 * релизов.
 *
 * Соль не логируется и не попадает ни в базу, ни в файл.
 */
final class VisitorHash
{
    private const KEY_PREFIX = 'analytics:salt:';

    /** Двое суток: ключ заведомо переживает свой день в любом часовом поясе. */
    private const SALT_TTL = 172_800;

    public function for(string $ip, ?string $userAgent): string
    {
        return substr(hash('sha256', $this->salt().'|'.$ip.'|'.($userAgent ?? '')), 0, 32);
    }

    /**
     * Соль текущего дня — по часовому поясу приложения.
     *
     * `SET NX`, затем `GET`: два воркера, встретившие полночь одновременно,
     * оба получат соль того, кто записал первым. «Прочитать, а если пусто —
     * записать» дало бы каждому свою соль и двух посетителей из одного.
     */
    private function salt(): string
    {
        $key = self::KEY_PREFIX.CarbonImmutable::now()->format('Y-m-d');
        $redis = Redis::connection();

        $salt = $redis->get($key);

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        $redis->set($key, bin2hex(random_bytes(32)), 'EX', self::SALT_TTL, 'NX');

        $salt = $redis->get($key);

        if (! is_string($salt) || $salt === '') {
            // Хеш без соли обратим перебором адресов — лучше не посчитать
            // просмотр вовсе. Исключение ловит middleware учёта.
            throw new RuntimeException('Соль посетителя не записалась в Redis.');
        }

        return $salt;
    }
}
