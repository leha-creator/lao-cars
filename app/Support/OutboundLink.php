<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Адрес кнопки, уводящей посетителя на другой сайт.
 *
 * Заведён под кнопку первого экрана страницы запчастей: она ведёт
 * в каталог автозапчастей на стороннем сервисе, и адрес вводит
 * администратор в настройках сайта.
 *
 * **Почему не хватает экранирования Blade.** `{{ }}` экранирует значение,
 * но не схему: `javascript:alert(1)` в `href` переживает его без единого
 * изменённого символа и исполняется по клику. Останавливает это только
 * проверка схемы.
 *
 * **Проверок две, и это не дубль** — то же устройство, что у `MapEmbed`.
 * Правило формы (`ManageSiteSettings`) ловит опечатку при вводе и объясняет
 * её человеку. Проверка на выводе (`PartsHeroContent`) ловит значение,
 * приехавшее мимо формы, — сидом, миграцией, `psql`. Обе зовут ОДИН метод:
 * два похожих правила разошлись бы в сторону «форма приняла, страница
 * отклонила», то есть в молчаливо пропавшую кнопку.
 *
 * Списка разрешённых хостов, как у карты, здесь нет намеренно: карта
 * встраивает чужую страницу в нашу, а кнопка уводит на неё — адрес каталога
 * выбирает заказчик, и угадывать его хост заранее нечем.
 */
final class OutboundLink
{
    /**
     * Годится ли адрес для `href` кнопки.
     *
     * Только абсолютный адрес по `http(s)` с хостом. Якоря и относительные
     * пути не принимаются: кнопка ведёт на другой сервис, а не по сайту.
     */
    public static function isAllowed(?string $url): bool
    {
        return self::host($url) !== null;
    }

    /**
     * Ведёт ли адрес за пределы сайта.
     *
     * По ответу шаблон ставит `target="_blank"` и `rel="noopener noreferrer"`.
     * Адрес, не прошедший `isAllowed()`, внешним не считается — выводить
     * его всё равно нельзя.
     */
    public static function isExternal(?string $url): bool
    {
        $host = self::host($url);

        if ($host === null) {
            return false;
        }

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        return ! is_string($own) || mb_strtolower($own) !== $host;
    }

    /**
     * Хост допустимого адреса в нижнем регистре — или `null`.
     */
    private static function host(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        // `parse_url()` на мусоре возвращает `false`, а на строке без схемы —
        // массив без ключа `scheme`. Оба случая — не адрес.
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? mb_strtolower((string) $parts['scheme']) : null;
        $host = isset($parts['host']) ? mb_strtolower((string) $parts['host']) : null;

        // Список разрешённых схем, а не запрещённых: перечень запрещённого
        // всегда неполон — завтра появится схема, о которой мы не думали.
        if (! in_array($scheme, ['https', 'http'], strict: true) || $host === null || $host === '') {
            return null;
        }

        return $host;
    }
}
