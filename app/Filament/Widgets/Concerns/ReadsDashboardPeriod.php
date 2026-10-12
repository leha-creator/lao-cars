<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Enums\AnalyticsPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Период из фильтра страницы инфопанели.
 *
 * Фильтр один на страницу (`App\Filament\Pages\Dashboard`), виджеты своих
 * не заводят: запросы и посещения за разные периоды рядом — это два числа,
 * которые нельзя сравнить.
 */
trait ReadsDashboardPeriod
{
    use InteractsWithPageFilters;

    /**
     * Значение приходит из адресной строки и сессии, то есть от человека:
     * незнакомое или пустое превращается в умолчание, а не в исключение.
     */
    protected function period(): AnalyticsPeriod
    {
        $value = $this->pageFilters['period'] ?? null;

        if ($value instanceof AnalyticsPeriod) {
            return $value;
        }

        return AnalyticsPeriod::tryFrom(is_string($value) ? $value : '') ?? AnalyticsPeriod::Today;
    }
}
