<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Робот или человек — по User-Agent.
 *
 * В просмотры страниц робот не попадает, а в счётчике запросов получает
 * собственное поле: «сколько из нагрузки дают роботы» отвечает на вопрос
 * «почему запросов много, а посетителей мало».
 *
 * Проверка честно неполная: робот, представившийся браузером, пройдёт.
 * JS-маячок отсекал бы точнее, но это лишний POST через PHP на каждый
 * просмотр и статистика, не работающая без скрипта, — при том что сайт
 * намеренно работает без JS целиком. От такого робота страхует не этот
 * класс, а потолок `analytics.max_rows`.
 */
final class BotDetector
{
    /**
     * Пустой User-Agent считается роботом: браузеры его не опускают.
     */
    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        return preg_match((string) config('analytics.bot_pattern'), $userAgent) === 1;
    }
}
