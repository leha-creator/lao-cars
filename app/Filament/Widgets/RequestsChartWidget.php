<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AdminOnly;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\RequestCounter;
use Filament\Support\Colors\Color;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * График запросов, дошедших до приложения, с разбивкой по классу ответа.
 *
 * Считается то, что дошло до PHP: статику отдаёт nginx, и её здесь нет.
 * «Панель» и «Роботы» показаны отдельными рядами намеренно — без них три
 * открытые вкладки сотрудников и один поисковый робот выглядели бы как
 * посещаемость.
 */
class RequestsChartWidget extends ChartWidget
{
    use AdminOnly;
    use ReadsDashboardPeriod;

    /**
     * Раз в минуту, а не умолчательные `5s`: каждый опрос — запрос Livewire
     * с каждой открытой вкладки, и он сам попадает в ряд «Панель» этого же
     * графика. Чаще минуты график начал бы рисовать сам себя.
     */
    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string|Htmlable|null
    {
        return 'Запросы к приложению';
    }

    public function getDescription(): string|Htmlable|null
    {
        $totals = app(RequestCounter::class)->totals($this->period());

        return 'Всего: '.number_format(array_sum($totals), 0, ',', ' ')
            .' · ошибок сервера (5xx): '.number_format($totals['site_5xx'], 0, ',', ' ');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $period = $this->period();
        $series = app(RequestCounter::class)->series($period);
        $format = $period->granularity() === 'day' ? 'd.m' : 'H:i';

        $column = static fn (string ...$fields): array => array_map(
            static fn (array $bucket): int => array_sum(array_map(static fn (string $field): int => $bucket[$field], $fields)),
            $series,
        );

        // Цвета — оттенки штатной палитры Filament, своих значений нет:
        // зелёный, янтарный и красный здесь те же, что у `success`,
        // `warning` и `danger` в карточках выше.
        return [
            'labels' => array_map(static fn (array $bucket): string => $bucket['at']->format($format), $series),
            'datasets' => [
                ['label' => 'Сайт: успешно', 'data' => $column('site_2xx', 'site_3xx'), 'backgroundColor' => Color::Green[500]],
                ['label' => 'Сайт: ошибки посетителя (4xx)', 'data' => $column('site_4xx'), 'backgroundColor' => Color::Amber[500]],
                ['label' => 'Сайт: ошибки сервера (5xx)', 'data' => $column('site_5xx'), 'backgroundColor' => Color::Red[500]],
                ['label' => 'Роботы', 'data' => $column('bot'), 'backgroundColor' => Color::Gray[400]],
                ['label' => 'Панель и служебные', 'data' => $column('panel', 'system'), 'backgroundColor' => Color::Sky[500]],
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
                'x' => ['stacked' => true],
                // Запросы — целые: без `precision` ось на малых числах
                // рисует «0,5 запроса».
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
