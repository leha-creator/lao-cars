<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Weekday;
use Illuminate\Support\Facades\Log;

/**
 * Расписание работы компании: структура настройки → человеческая строка
 * и `openingHoursSpecification` для микроразметки (веха 4.14).
 *
 * Живёт в `app/Support/` по прецеденту `ThumbnailPath` и `SocialLinks`:
 * это чистая функция над значением настройки — ни диска, ни БД, ни HTTP.
 * Обращаться к ней могут и компонент подвала, и контроллер `/contacts`,
 * и страница настроек Filament, не нарушая правила зависимостей
 * `ARCHITECTURE.md`.
 *
 * Публичная строка СОБИРАЕТСЯ, а не хранится. Хранить её рядом со
 * структурой значило бы завести два источника одной правды: заполнены
 * оба — какой показывать, и почему администратор, поправивший дни,
 * не видит изменений на сайте. Тот же вывод, что у правила `RULES.md`
 * про фолбэк заголовка страницы.
 *
 * Форма значения настройки:
 *
 *     [
 *         'days' => [
 *             'mon' => ['closed' => false, 'open' => '09:00', 'close' => '21:00'],
 *             ...
 *             'sun' => ['closed' => true],
 *         ],
 *         'note' => 'в праздничные дни по записи',
 *     ]
 */
final class WorkSchedule
{
    /**
     * Сутки целиком в том виде, в каком их может выдать `TimePicker`
     * формы: верхняя граница пикера — 23:59, полуночи «следующего дня»
     * он выразить не умеет.
     *
     * Это же и конвенция schema.org для круглосуточной работы —
     * в документации прямо предписано использовать 00:00–23:59.
     */
    private const string DAY_START = '00:00';

    private const string DAY_END = '23:59';

    /**
     * @param  array<string, array{open: string, close: string}|null>  $days  ключ — значение `Weekday`,
     *                                                                        `null` — выходной. Заполнены все семь ключей всегда.
     */
    private function __construct(
        private readonly array $days,
        private readonly ?string $note,
    ) {}

    /**
     * Нормализация значения настройки.
     *
     * Форма настроек пишет ключи безусловно, в том числе пустыми, а
     * удаление всех элементов даёт `null` вместо массива — правило
     * `RULES.md` про репитеры настроек. Поэтому «не массив», «нет дня»,
     * «день есть, времени нет» и «время есть, но мусор» приводятся
     * к одному состоянию «выходной», а не роняют страницу.
     */
    public static function fromSetting(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $rawDays = is_array($value['days'] ?? null) ? $value['days'] : [];

        $days = [];

        foreach (Weekday::cases() as $case) {
            $days[$case->value] = self::normalizeDay($rawDays[$case->value] ?? null);
        }

        $note = $value['note'] ?? null;
        $note = is_string($note) ? trim($note) : null;

        return new self($days, $note === '' ? null : $note);
    }

    /**
     * Значение настройки по умолчанию: семь рабочих дней 09:00–21:00.
     *
     * Ровно то, чем компания работает сегодня («Пн–Вс, 9:00–21:00»
     * в старой строке `contacts.work_hours`). Живёт здесь, а не в сиде,
     * потому что нужно трём местам сразу: сиду, миграции замены настройки
     * и пресету «Без выходных» в форме админки. Три копии семи строк
     * разошлись бы на первой же правке часов.
     *
     * @return array{days: array<string, array{closed: bool, open: string, close: string}>, note: null}
     */
    public static function defaultSetting(string $open = '09:00', string $close = '21:00'): array
    {
        $days = [];

        foreach (Weekday::cases() as $case) {
            $days[$case->value] = ['closed' => false, 'open' => $open, 'close' => $close];
        }

        return ['days' => $days, 'note' => null];
    }

    /**
     * Публичная строка расписания или `null`, если рабочих дней нет вовсе.
     *
     * Семь одинаковых рабочих дней дают «Без выходных, 9:00–21:00» —
     * это то, что заказчик назвал минимумом требования. Разное время
     * по дням даёт перечисление групп подряд идущих дней:
     * «Пн–Пт 9:00–19:00, Сб 10:00–16:00, Вс выходной».
     */
    public function label(): ?string
    {
        if (! $this->hasWorkingDays()) {
            // Расписание, в котором закрыто всегда, почти наверняка означает
            // очищенную форму, а не круглогодичный выходной. Подвал в этом
            // месте покажет пустоту, и связать её с настройкой будет нечем.
            Log::warning('[Расписание] в настройке contacts.schedule нет ни одного рабочего дня', [
                'setting' => 'contacts.schedule',
            ]);

            return null;
        }

        if ($this->isEveryDayAlike()) {
            $first = $this->days[Weekday::Mon->value];

            return $this->isRoundTheClock($first)
                ? 'Круглосуточно'
                : 'Без выходных, '.$this->timeRange($first);
        }

        $parts = [];

        foreach ($this->groups() as $group) {
            $parts[] = $this->groupLabel($group);
        }

        return implode(', ', $parts);
    }

    /**
     * Примечание рядом с часами — как есть, без сборки.
     */
    public function note(): ?string
    {
        return $this->note;
    }

    /**
     * Часы работы для JSON-LD.
     *
     * Выходные дни в спецификацию не попадают вовсе: `openingHours`
     * описывает, когда организация ОТКРЫТА, и запись с нулевым интервалом
     * означала бы не «закрыто», а «открыто нисколько», то есть ошибку
     * данных в глазах валидатора.
     *
     * Дни склеены в те же группы, что и в публичной строке: одна запись
     * с пятью днями вместо пяти одинаковых записей.
     *
     * @return list<array{'@type': string, dayOfWeek: list<string>, opens: string, closes: string}>
     */
    public function openingHoursSpecification(): array
    {
        $specification = [];

        foreach ($this->groups() as $group) {
            if ($group['hours'] === null) {
                continue;
            }

            $specification[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => array_map(
                    static fn (Weekday $day): string => $day->schemaOrgUri(),
                    $group['days'],
                ),
                'opens' => $group['hours']['open'],
                'closes' => $group['hours']['close'],
            ];
        }

        return $specification;
    }

    public function hasWorkingDays(): bool
    {
        foreach ($this->days as $hours) {
            if ($hours !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Нормализованные дни — для формы админки и сторожей.
     *
     * @return array<string, array{open: string, close: string}|null>
     */
    public function days(): array
    {
        return $this->days;
    }

    /**
     * Один день значения настройки → `['open' => …, 'close' => …]` или `null`.
     *
     * Пустоту проверяем строго (`=== null || === ''`), а не через `empty()`:
     * правило `RULES.md`. Послабление здесь стоило бы полуночи — время
     * «00:00» состоит из символов, которые PHP охотно считает пустотой.
     *
     * @return array{open: string, close: string}|null
     */
    private static function normalizeDay(mixed $day): ?array
    {
        if (! is_array($day)) {
            return null;
        }

        if (filter_var($day['closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $open = self::normalizeTime($day['open'] ?? null);
        $close = self::normalizeTime($day['close'] ?? null);

        if ($open === null || $close === null || $close <= $open) {
            return null;
        }

        return ['open' => $open, 'close' => $close];
    }

    /**
     * «9:00», «09:00:00», «24:00» → «09:00»; мусор → `null`.
     *
     * `24:00` из плана и сидов приводится к `23:59`: пикер формы такого
     * значения выдать не может, а правило валидации «закрытие позже
     * открытия» на паре 00:00–00:00 сработало бы против собственной
     * настройки. Одна каноническая форма суток на весь класс — и строка,
     * и микроразметка, и форма говорят об одном и том же.
     */
    private static function normalizeTime(mixed $time): ?string
    {
        if (! is_string($time) && ! is_int($time)) {
            return null;
        }

        $time = trim((string) $time);

        if ($time === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours === 24 && $minutes === 0) {
            return self::DAY_END;
        }

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * Группы подряд идущих дней с одинаковым состоянием.
     *
     * Склейка идёт по СОСЕДСТВУ, а не по совпадению: «Пн, Ср, Пт 9–19» —
     * это перечисление через день, и «Пн–Пт» на нём соврало бы, объявив
     * рабочими вторник и четверг.
     *
     * @return list<array{days: list<Weekday>, hours: array{open: string, close: string}|null}>
     */
    private function groups(): array
    {
        $groups = [];

        foreach (Weekday::cases() as $case) {
            $hours = $this->days[$case->value];
            $last = $groups === [] ? null : array_key_last($groups);

            if ($last !== null && $groups[$last]['hours'] === $hours) {
                $groups[$last]['days'][] = $case;

                continue;
            }

            $groups[] = ['days' => [$case], 'hours' => $hours];
        }

        return $groups;
    }

    /**
     * @param  array{days: list<Weekday>, hours: array{open: string, close: string}|null}  $group
     */
    private function groupLabel(array $group): string
    {
        $days = $group['days'];
        $first = $days[0];
        $last = $days[count($days) - 1];

        // Два соседних дня — это «Сб, Вс», а не «Сб–Вс»: диапазон из двух
        // элементов не короче перечисления и читается как пропуск чего-то
        // посередине.
        $names = match (count($days)) {
            1 => $first->label(),
            2 => $first->label().', '.$last->label(),
            default => $first->label().'–'.$last->label(),
        };

        if ($group['hours'] === null) {
            return $names.(count($days) === 1 ? ' выходной' : ' выходные');
        }

        return $names.' '.($this->isRoundTheClock($group['hours'])
            ? 'круглосуточно'
            : $this->timeRange($group['hours']));
    }

    /**
     * «09:00»/«21:00» → «9:00–21:00».
     *
     * @param  array{open: string, close: string}  $hours
     */
    private function timeRange(array $hours): string
    {
        return $this->humanTime($hours['open']).'–'.$this->humanTime($hours['close']);
    }

    /**
     * Ведущий ноль в часах уходит: он нужен машине (сортировка, JSON-LD),
     * а человеку «09:00» в подвале читается как выгрузка из системы.
     * Снимается ровно один ноль и только в часах — «00:30» обязано стать
     * «0:30», а не «:30», и минуты не трогаются никогда.
     */
    private function humanTime(string $time): string
    {
        return (string) preg_replace('/^0(\d:)/', '$1', $time);
    }

    /**
     * @param  array{open: string, close: string}  $hours
     */
    private function isRoundTheClock(array $hours): bool
    {
        return $hours['open'] === self::DAY_START && $hours['close'] === self::DAY_END;
    }

    private function isEveryDayAlike(): bool
    {
        $first = $this->days[Weekday::Mon->value];

        if ($first === null) {
            return false;
        }

        foreach ($this->days as $hours) {
            if ($hours !== $first) {
                return false;
            }
        }

        return true;
    }
}
