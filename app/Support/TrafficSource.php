<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Источник захода на сайт — по заголовку `Referer`.
 *
 * Хранится только хост: полный адрес страницы-источника может нести
 * поисковый запрос или чужой идентификатор, а статистике он не нужен.
 */
final class TrafficSource
{
    /**
     * Подписи известных источников: ключ — окончание хоста.
     *
     * Сопоставление по окончанию, а не по равенству: у Яндекса и Google
     * десятки национальных и служебных доменов.
     */
    private const LABELS = [
        'yandex.ru' => 'Яндекс',
        'yandex.com' => 'Яндекс',
        'ya.ru' => 'Яндекс',
        'dzen.ru' => 'Дзен',
        'google.com' => 'Google',
        'google.ru' => 'Google',
        't.me' => 'Telegram',
        'telegram.org' => 'Telegram',
        'vk.com' => 'ВКонтакте',
        'vk.ru' => 'ВКонтакте',
        'avito.ru' => 'Авито',
        'mail.ru' => 'Mail.ru',
        'bing.com' => 'Bing',
        'duckduckgo.com' => 'DuckDuckGo',
    ];

    /**
     * Хост источника в нижнем регистре — или `null`.
     *
     * `null` означает и «заголовка нет», и «переход внутри сайта», и мусор:
     * различает эти случаи вызывающая сторона по самому заголовку
     * (`isInternal()`), а в колонку источника ни один из них не пишется.
     */
    public static function host(?string $referer, string $ownHost): ?string
    {
        $host = self::normalizedHost($referer);

        if ($host === null || $host === self::strip($ownHost)) {
            return null;
        }

        return $host;
    }

    /**
     * Пришёл ли посетитель с другой страницы этого же сайта.
     */
    public static function isInternal(?string $referer, string $ownHost): bool
    {
        $host = self::normalizedHost($referer);

        return $host !== null && $host === self::strip($ownHost);
    }

    /**
     * Подпись источника для таблицы инфопанели.
     */
    public static function label(?string $host): string
    {
        if ($host === null || $host === '') {
            return 'Прямой заход';
        }

        foreach (self::LABELS as $suffix => $label) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $label;
            }
        }

        return $host;
    }

    private static function normalizedHost(?string $referer): ?string
    {
        if ($referer === null || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);

        // Только http(s) с хостом: `android-app://…` и обрывки строк —
        // не источник, а мусор, и в топе источников им не место.
        if (! is_array($parts)
            || ! in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], strict: true)
            || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return mb_substr(self::strip((string) $parts['host']), 0, 255);
    }

    /**
     * Срезать `www.` и мобильный `m.`: `m.vk.com` и `vk.com` — один источник.
     */
    private static function strip(string $host): string
    {
        return (string) preg_replace('/^(www\.|m\.)+/', '', mb_strtolower($host));
    }
}
