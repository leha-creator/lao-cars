<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AdminOnly;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\TrafficReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Самые просматриваемые страницы сайта за период.
 *
 * Адрес — без строки запроса: комбинаций фильтров каталога сотни, и с ними
 * таблица рассыпалась бы на единичные строки.
 */
class TopPagesWidget extends TableWidget
{
    use AdminOnly;
    use ReadsDashboardPeriod;

    /**
     * Опроса нет: статистика отстаёт на минуту, а опрос — агрегат
     * по `page_views` с каждой открытой вкладки. У `TableWidget` свойства
     * нет вовсе — оно объявлено здесь и передаётся в `poll()`, чтобы
     * интервал у всех восьми виджетов читался одинаково и стерёгся одним
     * тестом.
     */
    protected ?string $pollingInterval = null;

    /** Строк в таблице. */
    private const LIMIT = 10;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Страницы')
            ->records(fn (): array => collect(app(TrafficReport::class)->topPages($this->period(), self::LIMIT))
                ->keyBy('path')
                ->all())
            ->columns([
                TextColumn::make('path')
                    ->label('Адрес')
                    ->url(fn (array $record): string => url($record['path']))
                    ->openUrlInNewTab(),
                TextColumn::make('views')
                    ->label('Просмотры')
                    ->numeric(thousandsSeparator: ' ')
                    ->alignEnd(),
                TextColumn::make('visitors')
                    ->label('Посетители')
                    ->numeric(thousandsSeparator: ' ')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->poll($this->pollingInterval)
            ->emptyStateHeading('Данных за период нет')
            ->emptyStateDescription('Статистика обновляется раз в минуту.');
    }
}
