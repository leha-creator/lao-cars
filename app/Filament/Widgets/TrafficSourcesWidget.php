<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AnalyticsPeriod;
use App\Filament\Widgets\Concerns\AdminOnly;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\TrafficReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Откуда приходят посетители: источники заходов и рекламные метки.
 *
 * Два блока в одной таблице — группами строк. Считаются только ЗАХОДЫ:
 * переход со страницы на страницу внутри сайта источником не является.
 *
 * Блока «Рекламные метки» нет, пока нет меток, — правило проекта «пустой
 * блок не рендерится»: заголовок группы над пустотой читался бы как
 * поломка, а не как «рекламу не запускали».
 */
class TrafficSourcesWidget extends TableWidget
{
    use AdminOnly;
    use ReadsDashboardPeriod;

    /**
     * Опроса нет — см. `TopPagesWidget`: свойство объявлено здесь
     * и передаётся в `poll()`.
     */
    protected ?string $pollingInterval = null;

    public const GROUP_SOURCES = 'Источники';

    public const GROUP_CAMPAIGNS = 'Рекламные метки';

    /** Строк в каждом блоке. */
    private const LIMIT = 10;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Источники заходов')
            ->records(fn (): array => $this->rows($this->period()))
            ->columns([
                TextColumn::make('label')
                    ->label('Откуда'),
                TextColumn::make('entries')
                    ->label('Заходы')
                    ->numeric(thousandsSeparator: ' ')
                    ->alignEnd(),
            ])
            ->groups([
                Group::make('group')
                    ->label('Блок')
                    ->titlePrefixedWithLabel(false)
                    ->collapsible(false),
            ])
            ->defaultGroup('group')
            ->groupingSettingsHidden()
            ->paginated(false)
            ->poll($this->pollingInterval)
            ->emptyStateHeading('Данных за период нет')
            ->emptyStateDescription('Статистика обновляется раз в минуту.');
    }

    /**
     * Строки обоих блоков подряд: сначала источники, затем метки.
     *
     * Порядок задаёт этот метод, а не таблица: у произвольных данных
     * группы собираются из идущих подряд строк.
     *
     * @return array<string, array{group: string, label: string, entries: int}>
     */
    private function rows(AnalyticsPeriod $period): array
    {
        $report = app(TrafficReport::class);
        $rows = [];

        foreach ($report->sources($period, self::LIMIT) as $source) {
            $rows['source:'.($source['host'] ?? '')] = [
                'group' => self::GROUP_SOURCES,
                'label' => $source['label'],
                'entries' => $source['entries'],
            ];
        }

        foreach ($report->campaigns($period, self::LIMIT) as $index => $campaign) {
            $rows['campaign:'.$index] = [
                'group' => self::GROUP_CAMPAIGNS,
                // source / medium / campaign — пустые части опускаются.
                'label' => implode(' / ', array_filter(
                    [$campaign['source'], $campaign['medium'], $campaign['campaign']],
                    static fn (?string $part): bool => $part !== null && $part !== '',
                )),
                'entries' => $campaign['entries'],
            ];
        }

        return $rows;
    }
}
