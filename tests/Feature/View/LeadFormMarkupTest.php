<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

/*
 * Разметка формы заявки как контракт (веха 4.7).
 *
 * Тест сторожит одно решение: `fetch` лёг ПОВЕРХ обычной POST-формы
 * и ничего в ней не заменил. Форма заявки — единственное, ради чего сайт
 * существует, и она обязана отправляться при неработающем `app.js`.
 *
 * Проверяется именно то, что ломается при «упрощении». Переписать форму
 * на кнопку `type="button"` с обработчиком короче и выглядит чище: JSON-путь
 * при этом продолжает работать, `LeadStoreJsonTest` остаётся зелёным,
 * а форма без скрипта перестаёт отправляться совсем — молча и целиком.
 * Ни один тест ответа сервера этого не поймает, потому что ломается
 * не ответ, а способность браузера запрос отправить.
 *
 * Оформление тест не трогает: классы, отступы и порядок полей — вёрстка,
 * и сравнение с ними краснело бы на каждой правке, не поймав ни одной
 * настоящей ошибки.
 */

/*
 * `@error` читает `$errors` из общих данных вида, а кладёт их туда
 * middleware `ShareErrorsFromSession` — то есть только при HTTP-запросе.
 * `Blade::render()` до него не доходит, и без этой подстановки шаблон
 * падает на первом же `@error` — не потому, что сломан, а потому, что
 * рендерится в обход HTTP-цикла.
 */
beforeEach(function (): void {
    View::share('errors', new ViewErrorBag);
});

it('keeps the form a plain post form that works without javascript', function () {
    $html = Blade::render('<x-lead-form />');

    expect($html)
        // Без `method`/`action` браузер отправит форму GET-ом на ту же
        // страницу: заявка не дойдёт вообще, а страница просто
        // перезагрузится — симптом «форма ничего не делает».
        ->toContain('method="POST"')
        ->and($html)
        ->toContain('action="'.route('leads.store').'"')
        // Токен CSRF без скрипта неоткуда взять, кроме разметки.
        ->and($html)
        ->toContain('name="_token"')
        // Кнопка отправки — `submit`, и только он инициирует отправку
        // формы браузером.
        ->and($html)
        ->toContain('type="submit"');
});

it('hangs the client handler on submit rather than on the button', function () {
    $html = Blade::render('<x-lead-form />');

    expect($html)
        // Обработчик на событии формы, а не на клике по кнопке: так
        // перехватывается и отправка по Enter из текстового поля.
        ->toContain('x-on:submit.prevent')
        ->and($html)
        ->toContain('x-data="leadForm()"')
        // Компонент зарегистрирован в `app.js` до `Alpine.start()`.
        // Отсутствие имени в разметке означало бы, что форма отправляется
        // перезагрузкой при живом скрипте, — то есть веха 4.7 не работает.
        ->and($html)
        ->not->toContain('x-on:click.prevent');
});

it('keeps server and client error containers separate', function () {
    // Серверные сообщения помечены `data-server-error`: по этой метке
    // первый клиентский сабмит их удаляет. Один общий контейнер на оба
    // источника затирался бы `x-text` при инициализации Alpine — тот же
    // класс ошибки, что запрет `x-model` на контроле с `@selected(old())`.
    $html = Blade::render('<x-lead-form />');

    expect($html)
        ->toContain('x-text="errors.phone"')
        ->and($html)
        ->toContain('x-cloak');
});
