<?php

/*
 * Раздача снимков экрана для статей справки (веха 4.15).
 *
 * Снимки лежат в `resources/help/screenshots/` — ВНЕ веб-корня, — и
 * отдаются маршрутом под `auth`. Отсюда две группы проверок.
 *
 * Первая: доступ. Гость не получает файл, вошедший — получает.
 *
 * Вторая: имя файла из адреса попадает в путь на диске, то есть это
 * ровно та ручка, через которую читают чужие файлы. Проверяется не одна
 * форма обхода, а несколько: слеш, процентное кодирование слеша,
 * нулевой байт, чужое расширение, верхний регистр.
 *
 * Отдельный сторож стоит на `Cache-Control`. Он выглядит проверкой
 * заголовка ради заголовка, но это регрессионный тест на конкретную
 * ловушку — см. комментарий рядом с ним.
 */

use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Имя существующего снимка, взятое С ДИСКА, а не записанное строкой.
 *
 * Иначе переименование снимка роняло бы сторожа раздачи, который
 * про конкретные снимки не знает и знать не должен: его предмет —
 * механика отдачи файла, а не состав папки. Состав стережёт `HelpTest`.
 */
function anyHelpScreenshotName(): string
{
    $files = File::files(resource_path('help/screenshots'));

    expect($files)->not->toBeEmpty('В папке снимков нет ни одного файла — сторожу нечего отдавать.');

    return $files[0]->getFilename();
}

it('redirects a guest to the panel login page', function () {
    $this->get(route('help.screenshot', ['name' => anyHelpScreenshotName()]))
        ->assertRedirect('/admin/login');
});

/*
 * Менеджер получает снимок наравне с администратором, и это не дыра.
 *
 * Раздача не знает про статьи и правами на них не управляет: доступ
 * к содержимому решает страница статьи (`Article::booted()`), а картинка
 * стоит внутри уже открытой статьи. Заводить здесь вторую проверку прав
 * значило бы завести второй источник правды о видимости — ровно то,
 * чего решение вехи 4.8 про ключи доступа и избегает.
 */
it('serves a screenshot to any signed-in employee', function (bool $manager) {
    $user = $manager
        ? User::factory()->manager()->create()
        : User::factory()->create();

    $this->actingAs($user)
        ->get(route('help.screenshot', ['name' => anyHelpScreenshotName()]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
})->with([
    'администратор' => false,
    'менеджер' => true,
]);

/*
 * Регрессионный тест на ловушку `BinaryFileResponse`, найденную
 * на приёмке.
 *
 * У его конструктора параметр `$public` по умолчанию `true`, и
 * `setPublic()` зовётся ПОСЛЕ применения переданных заголовков — то есть
 * написанный в контроллере `private` молча заменялся на `public`.
 * Снимок админки при этом разрешено сложить в ОБЩИЙ кеш (прокси
 * провайдера, корпоративный кеш), а это ровно та утечка, ради
 * предотвращения которой файлы и лежат вне `public/`.
 *
 * Симптом не виден ничем, кроме чтения заголовков: картинка отдаётся,
 * страница выглядит правильно. Поэтому проверка стоит здесь, а не
 * считается очевидной.
 */
it('marks the screenshot response as private', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('help.screenshot', ['name' => anyHelpScreenshotName()]));

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)
        ->toContain('private')
        // `no-cache` — «храни, но спроси перед показом»: переснятый
        // снимок обязан появиться сразу, без ручной очистки кеша.
        ->toContain('no-cache')
        ->not->toContain('public');
});

it('404s a well-formed name with no file behind it', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('help.screenshot', ['name' => 'nothing-here.png']))
        ->assertNotFound();
});

/*
 * Часть этих имён не доходит до контроллера вовсе — параметр маршрута
 * не пропускает слеши, — и это нормально: сторож проверяет РЕЗУЛЬТАТ,
 * а не то, какой слой отказал. Если однажды параметру разрешат слеши,
 * проверка имени в контроллере останется единственной защитой, и упасть
 * этот тест обязан именно тогда.
 */
it('refuses to serve anything outside the screenshots directory', function (string $name) {
    $response = $this->actingAs(User::factory()->create())
        ->get('/admin/help/image/'.$name);

    $response->assertNotFound();

    expect($response->getContent())
        ->not->toContain('APP_KEY')
        ->not->toContain('DB_PASSWORD');
})->with([
    'вверх по дереву' => '../../.env',
    'кодированный слеш' => '..%2f..%2f.env',
    'кодированный слеш в верхнем регистре' => '..%2F..%2F.env',
    'подкаталог' => 'sub/dir.png',
    'файл окружения' => '.env',
    'нулевой байт' => 'settings-contacts-tab.png%00.txt',
]);

/*
 * Проверено на падение: со снятой проверкой формы имени из пяти наборов
 * краснеет ровно один — «верхний регистр». Остальные и без неё дают 404,
 * потому что такого файла просто нет.
 *
 * Это и есть содержательный вклад проверки, и он про РАЗНИЦУ ПЛАТФОРМ:
 * файловая система Windows регистронезависима, и `is_file()` находит
 * там `SETTINGS-CONTACTS-TAB.PNG`, а на боевом Linux — нет. Без проверки
 * формы имени раздача вела бы себя на машине разработчика и на проде
 * по-разному, причём в сторону «у меня работает».
 *
 * Второй её вклад отложенный: если параметру маршрута однажды разрешат
 * слеши, эта проверка останется единственным, что стоит между адресом
 * и произвольным файлом на диске.
 */
it('refuses names that do not match the allowed shape', function (string $name) {
    $this->actingAs(User::factory()->create())
        ->get('/admin/help/image/'.$name)
        ->assertNotFound();
})->with([
    'верхний регистр' => 'SETTINGS-CONTACTS-TAB.PNG',
    'чужое расширение' => 'settings-contacts-tab.jpg',
    'без расширения' => 'settings-contacts-tab',
    'точка в имени' => 'settings.contacts.png',
    'подчёркивание' => 'settings_contacts.png',
]);
