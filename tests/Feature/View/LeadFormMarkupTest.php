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

    // Умолчание приёма заявок — «выключено» (веха 4.17), а этот файл
    // проверяет разметку САМОЙ формы. Без включения `x-lead-form` отдаёт
    // блок «приём приостановлен», и все сторожа ниже проверяли бы его.
    enableLeadForms();
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

it('asks for consent with an unchecked checkbox', function () {
    // Предустановленная галочка согласием не является: согласие обязано
    // быть конкретным, информированным и однозначным действием человека.
    $html = Blade::render('<x-lead-form />');

    // Регулярное выражение по САМОМУ тегу, а не поиск слова «checked»
    // по всей разметке: подстрока встречается в чужих атрибутах, и такой
    // сторож прошёл бы на предустановленной галочке.
    expect($html)
        ->toMatch('/<input[^>]*type="checkbox"[^>]*name="consent"[^>]*>/')
        ->toMatch('/<input[^>]*name="consent"[^>]*value="1"[^>]*>/')
        // `required` держит проверку в браузере, `accepted` в
        // `StoreLeadRequest` — на сервере. Первое без второго обходится
        // в devtools, второе без первого заставляет ждать ответа сервера.
        ->toMatch('/<input[^>]*name="consent"[^>]*\srequired[^>]*>/')
        ->not->toMatch('/<input[^>]*name="consent"[^>]*\schecked[^>]*>/');
});

it('links the policy from the consent label without wrapping the checkbox', function () {
    // Ловушка, ради которой этот сторож и написан. Все остальные поля формы
    // обёрнуты в `<label>` целиком; для чекбокса так нельзя — внутри подписи
    // стоит ссылка на политику, и клик по ней ВНУТРИ `<label>` переключил бы
    // чекбокс. То есть человек, пошедший читать документ, молча дал бы или
    // отозвал согласие.
    $html = Blade::render('<x-lead-form />');

    expect($html)
        // Подпись связана с чекбоксом через `for`, а не через вложение.
        ->toMatch('/<label for="consent-[A-Za-z0-9]+"/')
        ->toContain(route('privacy.index'))
        // И главное: чекбокса нет НИ В ОДНОМ `<label>`. Выражение ищет
        // `name="consent"` между открывающим и ближайшим закрывающим
        // тегом подписи.
        ->not->toMatch('/<label\b(?:(?!<\/label>).)*name="consent"/s');
});

it('gives every form on the page its own consent checkbox id', function () {
    // Форм на странице может быть две: карточка автомобиля несёт свою,
    // `x-lead-section` — свою. Одинаковый `id` дал бы клик по подписи
    // второй формы, переключающий чекбокс первой: разметка валидна на вид,
    // ошибок в консоли нет, тесты зелёные.
    $first = Blade::render('<x-lead-form />');
    $second = Blade::render('<x-lead-form />');

    preg_match('/id="(consent-[A-Za-z0-9]+)"/', $first, $firstId);
    preg_match('/id="(consent-[A-Za-z0-9]+)"/', $second, $secondId);

    expect($firstId[1] ?? null)->not->toBeNull()
        ->and($secondId[1] ?? null)->not->toBeNull()
        ->and($firstId[1])->not->toBe($secondId[1]);
});
