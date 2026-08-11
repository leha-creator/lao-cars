<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Pages;

use App\Enums\ServiceCategory;
use App\Filament\Actions\HelpAction;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListServices extends ListRecords
{
    /** Ключ вкладки «Все» — вынесен, потому что на него смотрит таблица. */
    public const string ALL_TAB = 'all';

    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            HelpAction::make('price-list'),
        ];
    }

    /**
     * Вкладки по категориям, а не фильтр в общем списке.
     *
     * `sort_order` осмыслен внутри категории: сид нумерует позиции с нуля
     * в каждой, и страница автосервиса собирается блоками «категория +
     * порядок». Перетаскивание в общем списке присвоило бы сквозные
     * номера и перемешало бы все пять блоков разом — поэтому вкладка
     * сужает выборку, а пересортировка на «Всех» выключена
     * (см. `ServicesTable`).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            self::ALL_TAB => Tab::make('Все')
                ->badge(Service::query()->count()),
        ];

        foreach (ServiceCategory::cases() as $category) {
            $tabs[$category->value] = Tab::make($category->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('category', $category))
                ->badge(Service::query()->where('category', $category)->count());
        }

        return $tabs;
    }
}
