<?php

use App\Support\MapEmbed;
use Illuminate\Support\Facades\Log;

/*
 * Адрес виджета карты для страницы контактов (веха 4.5).
 *
 * Проверяется в первую очередь ОТКЛОНЕНИЕ, а не приём: значение настройки
 * едет прямо в `src` нашего iframe, то есть администратор панели описывает,
 * чей сайт покажется внутри страницы «ЛАО КАРС». Пропущенная сюда чужая
 * ссылка — это не опечатка в вёрстке, а чужая страница под нашим доменом
 * в глазах посетителя.
 *
 * Отдельный файл, а не пара кейсов внутри `ContactsPageTest`: список хостов
 * проверяется значениями, а не HTML, и утонуть среди проверок разметки
 * ему нельзя.
 */

it('takes a configured yandex url as is', function (string $url) {
    expect(MapEmbed::isAllowed($url))->toBeTrue()
        ->and(MapEmbed::resolve($url, 'Москва, Тестовая, 2'))->toBe($url);
})->with([
    'https://yandex.ru/map-widget/v1/?um=constructor%3Aabc&source=constructor',
    'https://www.yandex.ru/maps/-/CDabc',
    'https://yandex.com/map-widget/v1/?ll=37.4%2C55.8&z=16',
    'https://api-maps.yandex.ru/services/constructor/1.0/js/?um=constructor%3Aabc',
]);

it('rejects everything that is not an https yandex url', function (string $url) {
    Log::spy();

    expect(MapEmbed::isAllowed($url))->toBeFalse();
})->with([
    // Чужой сайт целиком.
    'https://example.com/map',
    // Схема. Проверка идёт по белому списку («строго https»), а не
    // по чёрному («не javascript»): список запрещённого всегда неполон.
    'http://yandex.ru/map-widget/v1/',
    'javascript:alert(1)',
    'data:text/html,<h1>hi</h1>',
    // Двойник: хост СОДЕРЖИТ «yandex.ru» и не имеет к нему отношения.
    // Проверка на вхождение подстроки пропустила бы его молча.
    'https://yandex.ru.evil.com/map-widget/v1/',
    // Поддомен, которого нет в списке.
    'https://evil.yandex.ru.attacker.net/',
    // Схема с хостом в пути, а не в хосте.
    'https://evil.com/https://yandex.ru/map-widget/v1/',
    // Не адрес вовсе.
    'yandex.ru/map-widget',
    'не ссылка',
]);

it('compares the host case-insensitively', function () {
    // Хост регистронезависим по RFC, и «YANDEX.RU» — тот же хост.
    // Отклонить его значило бы объявить опечаткой верный адрес.
    expect(MapEmbed::isAllowed('https://YANDEX.RU/map-widget/v1/'))->toBeTrue();
});

it('falls back to a widget built from the address', function () {
    $url = MapEmbed::resolve(null, 'Москва, ул. Осенняя, 17, корп. 1');

    expect($url)->toStartWith('https://yandex.ru/map-widget/v1/?')
        ->and($url)->toContain(urlencode('Москва, ул. Осенняя, 17, корп. 1'))
        ->and($url)->toContain('z=16');
});

it('warns and falls back when the configured url is rejected', function () {
    Log::spy();

    $url = MapEmbed::resolve('https://example.com/map', 'Москва, Тестовая, 2');

    // Отклонённая настройка не оставляет страницу без карты: посетитель
    // не должен платить за опечатку администратора пустым блоком.
    expect($url)->toStartWith('https://yandex.ru/map-widget/v1/?');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Карта] адрес виджета отклонён'
            // В лог идёт ХОСТ, а не URL целиком: содержимое поля пишет
            // кто угодно с доступом к форме настроек, а логи читают
            // глазами и грепом.
            && $context['host'] === 'example.com'
            && ! array_key_exists('url', $context))
        ->once();
});

it('gives nothing when there is neither a setting nor an address', function () {
    // `null` означает «секции карты на странице нет вовсе». Пустая рамка
    // на её месте читается как поломка вёрстки, а не как «не настроено».
    expect(MapEmbed::resolve(null, null))->toBeNull()
        ->and(MapEmbed::resolve('', ''))->toBeNull()
        ->and(MapEmbed::externalLink(null))->toBeNull();
});

it('builds an external link to the full map for the same address', function () {
    // Ссылка рядом с картой — не украшение: iframe может не загрузиться
    // (блокировщик, корпоративная сеть, офлайн), и без неё блок в этом
    // случае оказывается пустым прямоугольником на месте адреса.
    expect(MapEmbed::externalLink('Москва, Тестовая, 2'))
        ->toStartWith('https://yandex.ru/maps/?')
        ->toContain(urlencode('Москва, Тестовая, 2'));
});
