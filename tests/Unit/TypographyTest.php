<?php

declare(strict_types=1);

use App\Support\Typography;

/*
 * Русская типографика абзацев (веха 4.14, пункт 6 постановки).
 *
 * Unit, а не Feature: `Typography` — чистая функция над строкой, ни базы,
 * ни фасадов, и поднимать ради неё приложение незачем.
 *
 * ЛОВУШКА ВСЕГО ФАЙЛА: неразрывный пробел невидим и в diff, и в редакторе,
 * и в выводе теста. Поэтому проверяется ИМЕННО СИМВОЛ U+00A0, а не
 * «выглядит правильно», а в сообщениях он подменяется видимой меткой.
 */

/** Сделать неразрывные пробелы видимыми. */
function visible(?string $text): ?string
{
    return $text === null ? null : str_replace(Typography::NBSP, '[NB]', $text);
}

it('keeps the tail of the customer’s own example together', function () {
    // Дословно тот абзац, на который показал заказчик, и дословно тот
    // результат, которого он просил: «ещё до заявки», а не «заявки».
    $text = 'Короткая форма вместо длинного каталога: видно, сколько автомобилей подходит под запрос, ещё до заявки';

    expect(visible(Typography::tie($text)))
        ->toEndWith('ещё[NB]до[NB]заявки');
});

it('uses the character U+00A0 and not the html entity', function () {
    // Blade экранирует вывод `{{ }}`, и сущность напечаталась бы
    // на странице текстом «&nbsp;» — то есть ошибка была бы видна
    // посетителю, а не разработчику.
    $tied = Typography::tie('первое второе');

    expect($tied)->toContain(Typography::NBSP)
        ->and($tied)->not->toContain('&nbsp;')
        ->and(Typography::NBSP)->toBe("\u{00A0}");
});

it('pulls short prepositions to the word that follows them', function () {
    expect(visible(Typography::tie('Мы работаем в Москве и делаем это с 2016 года')))
        ->toContain('в[NB]Москве')
        ->toContain('и[NB]делаем');
});

it('does not break a hyphenated word that starts with a short syllable', function () {
    // Граница по пробелу, а не `\b`: последний превратил бы «во-первых»
    // в «во[NB]-первых».
    expect(visible(Typography::tie('во-первых, это работает уже сейчас')))
        ->toStartWith('во-первых,');
});

it('ties two words when three would overflow the container', function () {
    // Склеить три длинных слова в контейнере 440px значит вытолкнуть
    // строку за край, а переполнение хуже висячего слова: первое
    // выглядит небрежно, второе — сломано.
    // «качественно недорого» — ровно 20 символов, то есть предел;
    // «быстро качественно недорого» — 27, то есть уже нет.
    $tied = visible(Typography::tie('Мы делаем ремонт быстро качественно недорого'));

    expect($tied)->toContain('качественно[NB]недорого')
        // Третье слово к ним не приклеилось — суммарная длина не влезла.
        ->and($tied)->not->toContain('быстро[NB]качественно');
});

it('leaves a tail alone when even two words are too long', function () {
    // Не влезли и двое — оставляем как есть и полагаемся на `text-pretty`.
    $text = 'Электрификация трансконтинентального документооборота специалистами';

    expect(Typography::tie($text))->toBe($text);
});

it('passes null and an empty string straight through', function () {
    // Вызывающему не должно приходиться проверять их самому: иначе
    // проверка появится в семи местах и в одном из них будет забыта.
    expect(Typography::tie(null))->toBeNull()
        ->and(Typography::tie(''))->toBe('')
        ->and(Typography::tie('   '))->toBe('   ');
});

it('leaves a single word exactly as it was', function () {
    expect(Typography::tie('Одно'))->toBe('Одно');
});

it('collapses the indentation a blade slot brings with it', function () {
    // Текст приходит и из слота компонента, где он разложен по строкам
    // с отступом вёрстки. Без нормализации склейка считала бы длину
    // вместе с переводами строк и пятнадцатью пробелами отступа —
    // и не склеивала бы ничего.
    $fromSlot = "\n                        Короткая форма вместо каталога,\n                        ещё до заявки\n                    ";

    expect(visible(Typography::tie($fromSlot)))
        ->toBe('Короткая форма вместо каталога, ещё[NB]до[NB]заявки');
});

it('is idempotent, so a double pass cannot glue a paragraph solid', function () {
    // `\s` с флагом `/u` в этой сборке PCRE ловит и U+00A0, поэтому
    // повторный вызов сначала разбирает прошлую склейку, а потом
    // складывает её заново. Без этого текст, случайно прогнанный дважды,
    // стягивался бы в одну неразрывную строку — и переносился бы
    // как одно слово шириной с абзац.
    $once = Typography::tie('Короткая форма вместо длинного каталога, ещё до заявки');

    expect(Typography::tie($once))->toBe($once);
});

it('does not touch a text that needs nothing', function () {
    expect(Typography::tie('Слово'))->toBe('Слово');
});
