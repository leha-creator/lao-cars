<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars\Tables;

use App\Enums\CarStatus;
use App\Enums\EngineType;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Список автомобилей для наполнителя каталога.
 *
 * Фильтры здесь свои и с фильтрами каталога вехи 3.6 не связаны: те
 * живут в `CatalogFilter` и строятся под публичный GET-запрос.
 */
final class CarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Превью, а не оригинал: аксессор откатывается на `url`,
                // если обработка не удалась или фото залито до вехи 3.4.
                ImageColumn::make('mainPhoto.thumb_url')
                    ->label('Фото')
                    ->square()
                    ->height(56),

                TextColumn::make('brand.name')
                    ->label('Марка')
                    ->sortable(),

                TextColumn::make('model')
                    ->label('Модель')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('year')
                    ->label('Год')
                    ->sortable(),

                // divideBy по умолчанию 0: цена хранится в целых рублях,
                // делить её не нужно.
                TextColumn::make('price')
                    ->label('Цена')
                    ->money('RUB')
                    ->placeholder('по запросу')
                    ->sortable(),

                // Подпись и цвет приходят из enum-а через HasLabel/HasColor.
                // Хардкодить их здесь значит завести вторую копию словаря.
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->sortable(),

                // Правка прямо из списка: заводить карточку ради одного
                // переключателя «на главной» никто не будет.
                ToggleColumn::make('show_on_homepage')
                    ->label('На главной'),

                TextColumn::make('photos_count')
                    ->label('Фото')
                    ->counts('photos')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            // Порядок в подборке на главной — перетаскиванием. Колонка
            // sort_order и частичный индекс cars_homepage_index под это
            // уже есть (веха 3.2). Конфликта с defaultSort нет: при
            // включённой пересортировке CanSortRecords подменяет
            // сортировку колонкой порядка и отключает пагинацию.
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->preload()
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(CarStatus::class),

                SelectFilter::make('engine_type')
                    ->label('Двигатель')
                    ->options(EngineType::class),

                TernaryFilter::make('show_on_homepage')
                    ->label('На главной'),

                self::yearRangeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ]);
        // Массового удаления нет намеренно: вместе с автомобилем
        // каскадом уходят его фотографии и значения характеристик,
        // и цена случайного клика по «выбрать все» слишком высока.
    }

    private static function yearRangeFilter(): Filter
    {
        return Filter::make('year_range')
            ->label('Год выпуска')
            ->schema([
                TextInput::make('from')->label('С')->numeric(),
                TextInput::make('until')->label('По')->numeric(),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(
                    filled($data['from'] ?? null),
                    fn (Builder $query): Builder => $query->where('year', '>=', (int) $data['from']),
                )
                ->when(
                    filled($data['until'] ?? null),
                    fn (Builder $query): Builder => $query->where('year', '<=', (int) $data['until']),
                ));
    }

    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalDescription('Вместе с автомобилем будут удалены все его фотографии (включая файлы на диске) и значения характеристик. Действие необратимо.');
    }
}
