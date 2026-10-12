<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AdminOnly;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\TrafficReport;
use Filament\Support\Colors\Color;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * График посещаемости: просмотры и посетители по часам или дням.
 */
class VisitsChartWidget extends ChartWidget
{
    use AdminOnly;
    use ReadsDashboardPeriod;

    /**
     * Опроса нет — по той же причине, что у `TrafficStatsWidget`:
     * статистика отстаёт на минуту, а опрос стоит агрегата по `page_views`
     * с каждой открытой вкладки.
     */
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string|Htmlable|null
    {
        return 'Просмотры и посетители';
    }

    public function getDescription(): string|Htmlable|null
    {
        return 'Статистика обновляется раз в минуту и хранится неделю';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $period = $this->period();
        $series = app(TrafficReport::class)->series($period);
        $format = $period->granularity() === 'day' ? 'd.m' : 'H:i';

        // Цвета — оттенки штатной палитры Filament: янтарный — основной
        // цвет панели, серый — нейтральный.
        return [
            'labels' => array_map(static fn (array $bucket): string => $bucket['at']->format($format), $series),
            'datasets' => [
                [
                    'label' => 'Просмотры',
                    'data' => array_column($series, 'views'),
                    'borderColor' => Color::Amber[500],
                    'backgroundColor' => Color::Amber[500],
                ],
                [
                    'label' => 'Посетители',
                    'data' => array_column($series, 'visitors'),
                    'borderColor' => Color::Gray[400],
                    'backgroundColor' => Color::Gray[400],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
