<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AdminOnly;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\TrafficReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Итоги посещаемости за период: просмотры, посетители, заявки, конверсия,
 * доля мобильных.
 */
class TrafficStatsWidget extends StatsOverviewWidget
{
    use AdminOnly;
    use ReadsDashboardPeriod;

    /**
     * Опроса нет: статистика отстаёт на минуту по устройству, а каждый
     * опрос — запрос Livewire и несколько агрегатов по `page_views`.
     * Свежие числа — обновлением страницы или сменой периода.
     */
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Посещаемость';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $summary = app(TrafficReport::class)->summary($this->period());

        return [
            Stat::make('Просмотры', $this->number($summary['views']))
                ->description('Открытий страниц сайта'),

            // Подпись — «по дням», а не «уникальные»: посетитель обезличен
            // хешем, который меняется каждые сутки, и за неделю человек
            // посчитан столько раз, сколько дней заходил. Оговорка обязана
            // стоять прямо у числа, а не только в справке.
            Stat::make('Посетители (по дням)', $this->number($summary['visitors']))
                ->description('Кто заходил в разные дни, посчитан за каждый день'),

            Stat::make('Заявки', $this->number($summary['leads']))
                ->description('Оставлено за тот же период'),

            // «—», а не «0%»: при нуле посетителей доля не определена,
            // а «0%» читался бы как «никто не оставил заявку».
            Stat::make('Конверсия', $summary['conversion'] === null ? '—' : $this->percent($summary['conversion']))
                ->description('Заявки к посетителям'),

            Stat::make('С мобильных', $summary['mobile_share'] === null ? '—' : $this->percent($summary['mobile_share']))
                ->description('Доля просмотров с телефонов'),
        ];
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    private function percent(float $value): string
    {
        return number_format($value, 1, ',', ' ').'%';
    }
}
