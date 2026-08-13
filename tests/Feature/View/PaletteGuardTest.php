<?php

/*
 * Сторожа палитры (веха 4.11).
 *
 * Веха развела дизайн-систему на две ветки: тёмную по умолчанию и светлые
 * секции, помеченные `.theme-light`. Плата за это — новый класс ошибок,
 * которые НЕ роняют сборку и не видны в тёмной теме: класс на месте, стили
 * применились, просто читать нечего. Сторожа здесь закрывают четыре таких
 * случая — те, что уже случались или стоят в одном шаге от привычки.
 *
 * Файл отдельный, а не строки в `InfrastructureTest`: там живут проверки
 * PostgreSQL, Redis и очереди, и сторож вёрстки в этом файле просто
 * не найдут. Соседи здесь верные — `IconTest` и `LeadFormMarkupTest`.
 */

/**
 * Публичные шаблоны — всё, кроме вью панели.
 *
 * `resources/views/filament/` исключается намеренно и не «пока»: панель
 * живёт вне светлой темы, вне публичного layout и на своей сборке. Её
 * `push-devices.blade.php` содержит `text-white` и `dark:divide-white/10`
 * законно, и сторож, проверяющий «все `resources/views`», краснел бы
 * с первого запуска — и краснел бы не на том.
 *
 * @return list<string>
 */
function publicBladeFiles(): array
{
    $root = resource_path('views');
    $panel = $root.DIRECTORY_SEPARATOR.'filament'.DIRECTORY_SEPARATOR;

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (str_starts_with($file->getPathname(), $panel)) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

/**
 * Содержимое шаблона без блейдовских комментариев.
 *
 * Вырезать их обязательно: комментарии — это ПРОЗА о вёрстке, и в ней
 * классы называются по имени. `pagination/catalog.blade.php` упоминает
 * `bg-white` словом, объясняя, почему вендорный вид пагинации заменён
 * своим. Сторож, читающий файл целиком, поймал бы этот текст и потребовал
 * бы переписать объяснение вместо разметки.
 */
function bladeMarkup(string $path): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
}

/**
 * Собранный CSS публичной части или `null`, если сборки нет.
 *
 * Возвращает содержимое ВСЕХ файлов бандла склейкой: `@font-face` живут
 * в отдельном чанке (`fonts-*.css`), а токены темы — в `app-*.css`,
 * и какой из них искать, зависит от проверки.
 */
function builtCss(): ?string
{
    $files = glob(public_path('build/assets/*.css')) ?: [];

    if ($files === []) {
        return null;
    }

    return implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $files));
}

it('keeps the rouble glyph inside a declared unicode range', function () {
    $css = builtCss();

    // Без `npm run build` проверять нечего, и краснеть тут не на что:
    // сборка — не часть тестового прогона. Пропуск с внятной причиной
    // честнее зелёного теста, который ничего не открыл.
    if ($css === null) {
        $this->markTestSkipped('нет собранного CSS — запустите `npm run build`');
    }

    // Символ рубля U+20BD приезжает подмножеством `latin-ext`, и провайдер
    // объявляет его диапазоном `U+20A0-20CF` (или уже, но включая 20BD).
    // Подмножество убирают ради веса шрифтов, и симптом возвращается молча:
    // браузер уходит в системный шрифт на ОДНОМ символе посреди цены.
    preg_match_all('/unicode-range:\s*([^;}]+)/i', $css, $matches);

    $covers = false;

    foreach ($matches[1] as $ranges) {
        foreach (explode(',', $ranges) as $range) {
            if (! preg_match('/U\+([0-9A-F]+)(?:-([0-9A-F]+))?/i', trim($range), $bounds)) {
                continue;
            }

            $from = hexdec($bounds[1]);
            $to = hexdec($bounds[2] ?? $bounds[1]);

            if ($from <= 0x20BD && $to >= 0x20BD) {
                $covers = true;

                break 2;
            }
        }
    }

    expect($covers)->toBeTrue('ни один `unicode-range` в собранном CSS не покрывает U+20BD (символ рубля)');
});

it('has no white literals left in public templates', function () {
    // `border-white/8` ссылается на `--color-white`, а тот обязан остаться
    // белым: на нём держатся шапка, подвал и хиро, которые остаются тёмными.
    // Переопределить его на светлой обёртке нельзя, значит литерал в светлой
    // секции даёт НЕВИДИМУЮ границу — а выглядит это как «карточка без
    // обводки», то есть как решение дизайнера, а не как ошибка.
    $pattern = '/\b(?:bg|border|text|divide|ring|outline|from|via|to|fill|stroke|placeholder|caret|decoration|shadow)-white\b/';

    $offenders = [];

    foreach (publicBladeFiles() as $file) {
        if (preg_match($pattern, bladeMarkup($file))) {
            $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offenders)->toBe([], 'литерал `white` вместо токена линии или подложки');
});

it('has no text-page left in public templates', function () {
    // `text-page` означало «тёмный текст на жёлтой кнопке», и совпадение
    // с цветом фона было случайным: в тёмной теме подмена незаметна.
    // В светлой секции `--color-page` становится почти белым, и надпись
    // на жёлтой кнопке пропадает — при зелёной сборке и стоящей на месте
    // кнопке. Роль переехала на собственный токен `--color-on-accent`,
    // который с темой НЕ переключается.
    $offenders = [];

    foreach (publicBladeFiles() as $file) {
        if (preg_match('/\btext-page\b/', bladeMarkup($file))) {
            $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offenders)->toBe([], 'вместо `text-page` на акцентной заливке должен стоять `text-on-accent`');
});

it('never fills with bg-accent instead of bg-accent-solid', function () {
    // Самая свежая из молчаливых ловушек и единственная двойная: `bg-accent`
    // в светлой секции возьмёт ПЕРЕКЛЮЧЁННЫЙ (тёмный) акцент, а `text-on-accent`
    // на нём останется тёмным — тёмное по тёмному. Ошибка не роняет сборку
    // и не видна в тёмной теме, где оба токена равны, поэтому написать её
    // можно по привычке, по образцу старого кода или скопировав кусок
    // из git-истории.
    //
    // Отрицательный просмотр вперёд пропускает `bg-accent-solid`
    // и `bg-accent-hover`, но ловит и голый `bg-accent`, и тинт `bg-accent/14`.
    $offenders = [];

    foreach (publicBladeFiles() as $file) {
        if (preg_match('/\bbg-accent(?![-a-z])/', bladeMarkup($file))) {
            $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($offenders)->toBe([], 'акцентная заливка — это `bg-accent-solid`; `bg-accent` читает переключаемый токен');
});

it('switches the accent ink but not the accent fill in light sections', function () {
    // Проверка идёт по ИСХОДНОМУ `app.css`, а не по собранному: ошибка,
    // от которой стоит этот сторож, — «унификация» двух похожих токенов
    // руками, и совершается она здесь. Заодно тест не зависит от сборки.
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/\.theme-light\s*\{/');

    preg_match('/\.theme-light\s*\{(.*?)\n    \}/s', $css, $block);

    expect($block)->not->toBeEmpty('блок `.theme-light` не найден в `app.css`');

    // Границы блока проверяются отдельно, и это не перестраховка: три
    // утверждения ниже — отрицательные, и захвати регулярка лишнего или
    // слишком мало, они прошли бы ВХОЛОСТУЮ. `[x-cloak]` — первое правило
    // после блока, `color-scheme` — первое внутри него.
    expect($block[1])
        ->toContain('color-scheme: light')
        ->toContain('--color-page:')
        ->not->toContain('[x-cloak]');

    // Акцент-ЧЕРНИЛА переключается: `#f9c227` на почти белом даёт около
    // 1.4:1 при минимуме 4.5:1, то есть цена в карточке не читается.
    expect($block[1])->toContain('--color-accent:');

    // Акцент-ЗАЛИВКА и текст на ней — не переключаются: жёлтая плашка
    // с тёмной надписью одинакова на любом фоне, потому что читаемость
    // там обеспечивается внутри самой плашки. Уравнять эти токены значит
    // сломать либо цены, либо кнопки — смотря какой сочтут лишним.
    //
    // Пара «затемнение + чернила поверх фотографии» (веха 4.5) — из той же
    // семьи и по той же причине: читаемость там обеспечивается ВНУТРИ пары,
    // а не темой секции вокруг. Оба токена выглядят близкими родственниками
    // `--color-page` и `--color-ink` и первыми просятся «унифицировать»
    // с ними — после чего заголовок и стеклянные карточки полосы доверия
    // исчезают с кадра при зелёной сборке.
    expect($block[1])
        ->not->toContain('--color-scrim:')
        ->not->toContain('--color-on-photo:');
});

it('keeps the photo pair contrasting: dark scrim, light ink', function () {
    // Пара заведена ради ОДНОГО отказа. Без неё подложку пришлось бы красить
    // литералом `black` (запрещён тем же правилом, что и `white`) или
    // `from-page`, а текст — `text-ink`; и то и другое в светлой секции даёт
    // светлое по светлому, то есть содержимое, пропавшее с фотографии
    // при зелёной сборке и разметке на месте.
    //
    // Сторож проверяет именно ПРОТИВОПОЛОЖНОСТЬ светлот: значения можно
    // подкрутить, но затемнение обязано остаться тёмным, а чернила светлыми.
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('/--color-scrim:\s*oklch\(([0-9.]+)/', $css, $scrim);
    preg_match('/--color-on-photo:\s*oklch\(([0-9.]+)/', $css, $ink);

    expect($scrim)->not->toBeEmpty('токен `--color-scrim` не найден в `app.css`')
        ->and($ink)->not->toBeEmpty('токен `--color-on-photo` не найден в `app.css`')
        ->and((float) $scrim[1])->toBeLessThan(0.3)
        ->and((float) $ink[1])->toBeGreaterThan(0.9);
});
