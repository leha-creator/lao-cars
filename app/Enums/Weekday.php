<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * День недели в расписании работы компании (веха 4.14).
 *
 * Заведён вместе с настройкой `contacts.schedule`, заменившей свободную
 * строку `contacts.work_hours`. До него «дни работы» были частью текста
 * («Пн–Вс, 9:00–21:00»), то есть машине недоступны вовсе: ни выбрать
 * день в форме, ни отдать часы в микроразметку по строке нельзя.
 *
 * Порядок кейсов — с понедельника. Это не косметика: `WorkSchedule`
 * склеивает ПОДРЯД ИДУЩИЕ дни с одинаковым временем в диапазоны, и
 * порядок кейсов и есть определение слова «подряд». Перестановка
 * воскресенья в начало (порядок недели в США) молча превратила бы
 * «Пн–Пт» в два разорванных куска.
 */
enum Weekday: string implements HasLabel
{
    use HasLabels;

    case Mon = 'mon';
    case Tue = 'tue';
    case Wed = 'wed';
    case Thu = 'thu';
    case Fri = 'fri';
    case Sat = 'sat';
    case Sun = 'sun';

    /**
     * Короткая подпись — для публичной строки расписания.
     *
     * «Пн–Пт 9:00–19:00» читается с одного взгляда, а
     * «Понедельник–Пятница 9:00–19:00» переносится на вторую строку
     * в подвале и в карточке на `/contacts`.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mon => 'Пн',
            self::Tue => 'Вт',
            self::Wed => 'Ср',
            self::Thu => 'Чт',
            self::Fri => 'Пт',
            self::Sat => 'Сб',
            self::Sun => 'Вс',
        };
    }

    /**
     * Полная подпись — для формы админки.
     *
     * В форме семь строк подряд, и «Пн» в столбце подписей читается
     * как код, а не как день: администратор заполняет расписание раз
     * в год и не обязан держать сокращения в голове.
     */
    public function fullLabel(): string
    {
        return match ($this) {
            self::Mon => 'Понедельник',
            self::Tue => 'Вторник',
            self::Wed => 'Среда',
            self::Thu => 'Четверг',
            self::Fri => 'Пятница',
            self::Sat => 'Суббота',
            self::Sun => 'Воскресенье',
        };
    }

    /**
     * Идентификатор дня в словаре schema.org — для `openingHoursSpecification`.
     *
     * Полный URI, а не голое «Monday»: JSON-LD без `@context` на каждом
     * значении опирается на контекст документа, и валидатор Google
     * принимает обе формы, но полный URI однозначен и не зависит от того,
     * какой `@context` окажется у объекта завтра.
     */
    public function schemaOrgUri(): string
    {
        return 'https://schema.org/'.match ($this) {
            self::Mon => 'Monday',
            self::Tue => 'Tuesday',
            self::Wed => 'Wednesday',
            self::Thu => 'Thursday',
            self::Fri => 'Friday',
            self::Sat => 'Saturday',
            self::Sun => 'Sunday',
        };
    }
}
