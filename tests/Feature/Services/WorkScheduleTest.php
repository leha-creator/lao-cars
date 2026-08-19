<?php

/*
 * Сборка расписания работы (веха 4.14).
 *
 * Тесты лежат в Feature, а не в Unit, хотя `WorkSchedule` — чистая функция
 * над значением настройки: `label()` пишет WARN через фасад `Log`, а фасад
 * без поднятого приложения не работает. Прецедент места — `ImageProcessorTest`.
 *
 * Проверяется РЕЗУЛЬТАТ СБОРКИ, а не внутреннее устройство: публичная
 * строка и `openingHoursSpecification` — это то, что видят посетитель
 * и поисковик, и только они обязаны остаться прежними при любой правке
 * внутренностей.
 */

use App\Enums\Weekday;
use App\Support\WorkSchedule;
use Illuminate\Support\Facades\Log;

/**
 * Значение настройки: рабочая неделя с точечными правками.
 *
 * @param  array<string, array{closed?: bool, open?: string, close?: string}|null>  $overrides
 * @return array{days: array<string, mixed>, note: null|string}
 */
function schedule(array $overrides = [], string $open = '09:00', string $close = '21:00'): array
{
    $value = WorkSchedule::defaultSetting($open, $close);

    foreach ($overrides as $day => $hours) {
        $value['days'][$day] = $hours;
    }

    return $value;
}

it('calls a full week without a day off exactly that', function () {
    // Прямая просьба заказчика: «мы работаем без выходных, добавить хотя бы
    // „Без выходных“». Формулировка проверяется дословно — она и есть
    // требование, а не оформление.
    expect(WorkSchedule::fromSetting(schedule())->label())
        ->toBe('Без выходных, 9:00–21:00');
});

it('calls a round-the-clock week «Круглосуточно» instead of naming the hours', function () {
    // «Без выходных, 0:00–23:59» формально верно и читается как ошибка
    // ввода. Сутки напролёт называются словом.
    expect(WorkSchedule::fromSetting(schedule(open: '00:00', close: '24:00'))->label())
        ->toBe('Круглосуточно');
});

it('groups consecutive days and names the day off', function () {
    $value = schedule([
        'sat' => ['closed' => false, 'open' => '10:00', 'close' => '16:00'],
        'sun' => ['closed' => true],
    ], close: '19:00');

    expect(WorkSchedule::fromSetting($value)->label())
        ->toBe('Пн–Пт 9:00–19:00, Сб 10:00–16:00, Вс выходной');
});

it('does not collapse a gap in the middle of the week into a range', function () {
    // «Пн, Ср, Пт 9–19» — это перечисление через день, и «Пн–Пт» на нём
    // объявило бы рабочими вторник и четверг. Ошибка вида «сайт обещает
    // приём, компания закрыта»: заметит её клиент, приехавший впустую.
    $value = schedule(['tue' => ['closed' => true], 'thu' => ['closed' => true]]);

    expect(WorkSchedule::fromSetting($value)->label())
        ->toBe('Пн 9:00–21:00, Вт выходной, Ср 9:00–21:00, Чт выходной, Пт–Вс 9:00–21:00');
});

it('survives a value the form could realistically write', function () {
    // Правило `RULES.md`: форма настроек пишет ключи безусловно, в том
    // числе пустыми, а удаление всех элементов даёт `null`. Шаблон,
    // написанный по сиду, падает на проде — там, где настройки правят.
    $broken = [
        'days' => [
            'mon' => ['closed' => false, 'open' => '09:00', 'close' => '18:00'],
            'tue' => null,
            'wed' => ['closed' => false, 'open' => '', 'close' => ''],
            'thu' => ['closed' => false, 'open' => 'не время', 'close' => '18:00'],
            // Пятницы нет в значении вовсе.
            'sat' => ['closed' => false, 'open' => '18:00', 'close' => '09:00'],
        ],
        'note' => null,
    ];

    // Ни исключения, ни пустого экрана: всё непонятное — выходной.
    expect(WorkSchedule::fromSetting($broken)->label())
        ->toBe('Пн 9:00–18:00, Вт–Вс выходные');
});

it('treats a value that is not an array at all as an empty schedule', function () {
    Log::spy();

    expect(WorkSchedule::fromSetting('Пн–Вс, 9:00–21:00')->label())->toBeNull();
});

it('warns when the schedule has no working day at all', function () {
    Log::spy();

    $closed = ['days' => [], 'note' => null];

    expect(WorkSchedule::fromSetting($closed)->label())->toBeNull();

    // Расписание, в котором закрыто всегда, почти наверняка означает
    // очищенную форму, а не круглогодичный выходной. Подвал в этом месте
    // покажет пустоту, и связать её с настройкой без записи в логе нечем.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'нет ни одного рабочего дня'))
        ->once();
});

it('keeps the note as typed and drops an empty one', function () {
    expect(WorkSchedule::fromSetting(['days' => [], 'note' => '  в праздничные дни по записи  '])->note())
        ->toBe('в праздничные дни по записи')
        ->and(WorkSchedule::fromSetting(['days' => [], 'note' => '   '])->note())->toBeNull()
        ->and(WorkSchedule::fromSetting(['days' => []])->note())->toBeNull();
});

it('leaves days off out of the opening hours specification', function () {
    // schema.org описывает, КОГДА организация открыта. Запись с нулевым
    // интервалом означала бы не «закрыто», а «открыто нисколько», то есть
    // ошибку данных в глазах валидатора.
    $value = schedule(['sun' => ['closed' => true]], close: '19:00');

    $specification = WorkSchedule::fromSetting($value)->openingHoursSpecification();

    expect($specification)->toHaveCount(1)
        ->and($specification[0]['dayOfWeek'])->toHaveCount(6)
        ->and($specification[0]['dayOfWeek'])->not->toContain('https://schema.org/Sunday')
        ->and($specification[0]['opens'])->toBe('09:00')
        ->and($specification[0]['closes'])->toBe('19:00');
});

it('gives schema.org the padded time even though the page shows it trimmed', function () {
    // Человеку «09:00» читается как выгрузка из системы, машине «9:00»
    // — как поломанный формат. Два представления одного значения, и оба
    // обязаны остаться собой.
    $value = schedule(open: '09:00', close: '19:00');
    $work = WorkSchedule::fromSetting($value);

    expect($work->label())->toContain('9:00–19:00')
        ->and($work->openingHoursSpecification()[0]['opens'])->toBe('09:00');
});

it('reads days by key, not by their order in the value', function () {
    // PostgreSQL порядок ключей в jsonb не сохраняет — правило `RULES.md`.
    // Настройка возвращается из базы с днями по алфавиту, и сборка,
    // читающая их по порядку, дала бы «Пт–Ср» на обычной рабочей неделе.
    $value = schedule(['sun' => ['closed' => true]]);
    $value['days'] = array_reverse($value['days'], preserve_keys: true);

    expect(WorkSchedule::fromSetting($value)->label())
        ->toBe('Пн–Сб 9:00–21:00, Вс выходной');
});

it('keeps the weekday order the range collapsing depends on', function () {
    // Порядок кейсов енама и есть определение слова «подряд». Перестановка
    // воскресенья в начало (порядок недели в США) молча разорвала бы
    // «Пн–Пт» на два куска.
    expect(array_map(fn (Weekday $day): string => $day->value, Weekday::cases()))
        ->toBe(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
});
