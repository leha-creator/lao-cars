<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;

/**
 * Период фильтра инфопанели администратора.
 *
 * Один на страницу: запросы и посещения за разные периоды рядом — это
 * два числа, которые нельзя сравнить.
 *
 * Длиннее недели периода нет и быть не может: сырые просмотры живут
 * `analytics.retention_days`, а часовые корзины счётчика запросов —
 * `analytics.counter_ttl_days`.
 */
enum AnalyticsPeriod: string implements HasLabel
{
    use HasLabels;

    case LastHour = 'last_hour';

    case Today = 'today';

    case Yesterday = 'yesterday';

    case Last24Hours = 'last_24_hours';

    case Last7Days = 'last_7_days';

    public function label(): string
    {
        return match ($this) {
            self::LastHour => 'Последний час',
            self::Today => 'Сегодня',
            self::Yesterday => 'Вчера',
            self::Last24Hours => '24 часа',
            self::Last7Days => '7 дней',
        };
    }

    /**
     * Границы периода, обе включительно.
     *
     * Время — в часовом поясе приложения (`Europe/Moscow`), а не UTC:
     * иначе «сегодня» начиналось бы в три ночи.
     *
     * «7 дней» — сегодня и шесть предыдущих дней: ровно то окно, которое
     * оставляет ночная чистка, поэтому период всегда полный.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $now = CarbonImmutable::now();

        return match ($this) {
            self::LastHour => [$now->subHour(), $now],
            self::Today => [$now->startOfDay(), $now],
            self::Yesterday => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            self::Last24Hours => [$now->subDay(), $now],
            self::Last7Days => [$now->subDays(6)->startOfDay(), $now],
        };
    }

    /**
     * Шаг корзин графика: `hour` или `day`.
     */
    public function granularity(): string
    {
        return $this === self::Last7Days ? 'day' : 'hour';
    }

    /**
     * Начала корзин графика от первой до последней, без пропусков.
     *
     * Список строит перечисление, а не каждый потребитель: счётчик запросов
     * и отчёт о посещениях обязаны дать ряды одной длины с одними подписями,
     * иначе два графика на одной странице разъедутся по оси.
     *
     * @return list<CarbonImmutable>
     */
    public function buckets(): array
    {
        [$from, $to] = $this->range();

        $unit = $this->granularity();
        $cursor = $from->startOf($unit);
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($to)) {
            $buckets[] = $cursor;
            $cursor = $cursor->add(1, $unit);
        }

        return $buckets;
    }
}
