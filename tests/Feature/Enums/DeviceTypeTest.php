<?php

use App\Enums\DeviceType;

it('detects the device type from the user agent', function (?string $userAgent, DeviceType $device) {
    expect(DeviceType::fromUserAgent($userAgent))->toBe($device);
})->with([
    'Chrome на Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', DeviceType::Desktop],
    'Safari на macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', DeviceType::Desktop],
    'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', DeviceType::Mobile],
    'телефон на Android' => ['Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', DeviceType::Mobile],
    // В User-Agent iPad есть слово «Mobile» — порядок проверок обязан
    // это пережить.
    'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', DeviceType::Tablet],
    // Android без «Mobile» — планшет.
    'планшет на Android' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', DeviceType::Tablet],
    'пустая строка' => ['', DeviceType::Desktop],
    'null' => [null, DeviceType::Desktop],
]);

it('has a Russian label for every case', function () {
    expect(DeviceType::options())->toBe([
        'desktop' => 'Компьютеры',
        'mobile' => 'Телефоны',
        'tablet' => 'Планшеты',
    ]);
});
