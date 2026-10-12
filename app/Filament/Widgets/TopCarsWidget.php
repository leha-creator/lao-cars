<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Cars\CarResource;
use App\Filament\Widgets\Concerns\AdminOnly;
use App\Filament\Widgets\Concerns\ReadsDashboardPeriod;
use App\Services\TrafficReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Самые просматриваемые автомобили за период — и доли устройств.
 *
 * Доли устройств стоят в описании этой таблицы, а не отдельным виджетом:
 * это одна строка текста, и девятый блок ради неё не заводится.
 */
class TopCarsWidget extends TableWidget
{
    use AdminOnly;
    use ReadsDashboardPeriod;

    /**
     * Опроса нет — см. `TopPagesWidget`: свойство объявлено здесь
     * и передаётся в `poll()`.
     */
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** Строк в таблице. */
    private const LIMIT = 10;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Автомобили')
            ->description(fn (): ?string => $this->devicesLine())
            ->records(fn (): array => collect(app(TrafficReport::class)->topCars($this->period(), self::LIMIT))
                ->keyBy('car_id')
                ->all())
            ->columns([
                TextColumn::make('title')
                    ->label('Автомобиль')
                    ->url(fn (array $record): string => CarResource::getUrl('edit', ['record' => $record['car_id']])),
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

    /**
     * «Устройства: компьютеры 60%, телефоны 35%, планшеты 5%» — или `null`,
     * пока просмотров нет: строка из трёх прочерков ничего не сообщает.
     */
    private function devicesLine(): ?string
    {
        $devices = app(TrafficReport::class)->devices($this->period());

        if ($devices[0]['share'] === null) {
            return null;
        }

        return 'Устройства: '.implode(', ', array_map(
            static fn (array $row): string => mb_strtolower($row['device']->label()).' '.number_format($row['share'], 0, ',', ' ').'%',
            $devices,
        ));
    }
}
