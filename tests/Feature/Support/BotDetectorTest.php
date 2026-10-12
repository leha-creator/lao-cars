<?php

use App\Support\BotDetector;

/*
 * Робот или человек — по User-Agent.
 *
 * От ответа зависят сразу две цифры инфопанели: робот уходит в поле `bot`
 * счётчика запросов и НЕ попадает в просмотры. Ошибка в любую сторону
 * молчалива: статистика просто врёт.
 */

it('tells robots from browsers by the user agent', function (?string $userAgent, bool $isBot) {
    expect(BotDetector::isBot($userAgent))->toBe($isBot);
})->with([
    'Chrome на Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', false],
    'Safari на iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', false],
    'Яндекс.Браузер' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 YaBrowser/25.8.0.0 Safari/537.36', false],
    'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', true],
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true],
    'curl' => ['curl/8.5.0', true],
    'python-requests' => ['python-requests/2.32.3', true],
    'Go-http-client' => ['Go-http-client/2.0', true],
    'HeadlessChrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36', true],
    'UptimeRobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', true],
    'предпросмотр ссылки в Telegram' => ['TelegramBot (like TwitterBot)', true],
    'facebookexternalhit' => ['facebookexternalhit/1.1', true],
    // Браузеры User-Agent не опускают — пустой шлёт скрипт.
    'пустая строка' => ['', true],
    'одни пробелы' => ['   ', true],
    'null' => [null, true],
]);

it('takes the pattern from the config', function () {
    config(['analytics.bot_pattern' => '/acme-scraper/i']);

    expect(BotDetector::isBot('ACME-Scraper/1.0'))->toBeTrue()
        // Прежний список больше не действует — значит правило живёт
        // в конфиге, а не продублировано в классе.
        ->and(BotDetector::isBot('curl/8.5.0'))->toBeFalse();
});
