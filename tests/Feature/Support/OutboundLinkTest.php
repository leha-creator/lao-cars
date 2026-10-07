<?php

use App\Support\OutboundLink;

/*
 * Адрес кнопки, уводящей на другой сайт (первый экран страницы запчастей).
 *
 * Метод один на два рубежа — правило формы настроек и проверку на выводе, —
 * поэтому его таблица истинности проверяется здесь, а не через страницу:
 * через страницу видно только «кнопка есть или нет», а не почему.
 */

it('accepts only absolute http and https addresses', function (?string $url, bool $allowed) {
    expect(OutboundLink::isAllowed($url))->toBe($allowed);
})->with([
    'https' => ['https://parts.example.com/catalog', true],
    'http' => ['http://parts.example.com/', true],
    'схема в верхнем регистре' => ['HTTPS://parts.example.com', true],
    // Экранирование Blade эту строку не меняет ни на символ — останавливает
    // её только проверка схемы.
    'javascript' => ['javascript:alert(1)', false],
    'data' => ['data:text/html,<script>alert(1)</script>', false],
    'без схемы' => ['//parts.example.com/catalog', false],
    'относительный путь' => ['/catalog', false],
    'якорь' => ['#lead-form', false],
    'хост без схемы' => ['parts.example.com', false],
    'схема без хоста' => ['https://', false],
    'пустая строка' => ['', false],
    'null' => [null, false],
]);

it('tells an outside address from the site own one', function () {
    config(['app.url' => 'https://laocars.ru']);

    expect(OutboundLink::isExternal('https://parts.example.com/catalog'))->toBeTrue()
        ->and(OutboundLink::isExternal('https://laocars.ru/catalog'))->toBeFalse()
        // Регистр хоста не делает адрес чужим.
        ->and(OutboundLink::isExternal('https://LaoCars.ru/catalog'))->toBeFalse()
        // Негодный адрес внешним не считается: выводить его нельзя вовсе.
        ->and(OutboundLink::isExternal('javascript:alert(1)'))->toBeFalse()
        ->and(OutboundLink::isExternal(null))->toBeFalse();
});
