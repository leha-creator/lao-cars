<?php

use App\Support\TrafficSource;

/*
 * Источник захода по заголовку `Referer`.
 */

it('extracts the source host from the referer', function (?string $referer, ?string $host) {
    expect(TrafficSource::host($referer, 'laocars.ru'))->toBe($host);
})->with([
    'поисковик' => ['https://yandex.ru/search/?text=лао+карс', 'yandex.ru'],
    // `www.` и мобильный `m.` — тот же источник.
    'www' => ['https://www.google.com/', 'google.com'],
    'мобильный поддомен' => ['https://m.vk.com/away.php', 'vk.com'],
    'регистр хоста' => ['https://T.ME/laocars', 't.me'],
    // Свой сайт источником не является — с `www.` и без.
    'свой хост' => ['https://laocars.ru/catalog', null],
    'свой хост с www' => ['https://www.laocars.ru/catalog', null],
    'заголовка нет' => [null, null],
    'пустая строка' => ['', null],
    'мусор' => ['not a url', null],
    'не http' => ['android-app://com.google.android.gm/', null],
    'схема без хоста' => ['https://', null],
]);

it('never stores the path or the query of the referring page', function () {
    // В адресе страницы-источника бывает поисковый запрос человека.
    expect(TrafficSource::host('https://yandex.ru/search/?text=купить+zeekr+срочно', 'laocars.ru'))
        ->toBe('yandex.ru');
});

it('tells an internal navigation from an entry', function () {
    expect(TrafficSource::isInternal('https://laocars.ru/catalog', 'laocars.ru'))->toBeTrue()
        ->and(TrafficSource::isInternal('https://www.laocars.ru/', 'laocars.ru'))->toBeTrue()
        ->and(TrafficSource::isInternal('https://yandex.ru/', 'laocars.ru'))->toBeFalse()
        // Нет заголовка — это прямой заход, а не переход внутри сайта.
        ->and(TrafficSource::isInternal(null, 'laocars.ru'))->toBeFalse()
        ->and(TrafficSource::isInternal('мусор', 'laocars.ru'))->toBeFalse();
});

it('labels known sources and leaves unknown hosts as they are', function (?string $host, string $label) {
    expect(TrafficSource::label($host))->toBe($label);
})->with([
    'прямой заход' => [null, 'Прямой заход'],
    'пустая строка' => ['', 'Прямой заход'],
    'yandex.ru' => ['yandex.ru', 'Яндекс'],
    'поддомен Яндекса' => ['mail.yandex.ru', 'Яндекс'],
    'ya.ru' => ['ya.ru', 'Яндекс'],
    'google.com' => ['google.com', 'Google'],
    't.me' => ['t.me', 'Telegram'],
    'vk.com' => ['vk.com', 'ВКонтакте'],
    'неизвестный хост' => ['drom.ru', 'drom.ru'],
    // Окончание сверяется по границе домена: `notyandex.ru` — не Яндекс.
    'похожий хост' => ['notyandex.ru', 'notyandex.ru'],
]);
