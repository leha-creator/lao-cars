<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes\Tables;

use App\Enums\CarAttributeType;
use App\Filament\Resources\CarAttributes\Actions\DeleteCarAttributeAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class CarAttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Подпись')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('key')
                    ->label('Ключ')
                    ->badge()
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),

                // Подпись типа приходит из enum-а через HasLabel:
                // formatStateUsing() здесь означал бы копию словаря.
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge(),

                TextColumn::make('group')
                    ->label('Группа')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('values_count')
                    ->label('Заполнено у авто')
                    ->counts('values')
                    ->sortable(),

                ToggleColumn::make('show_in_card')
                    ->label('В карточке'),

                ToggleColumn::make('show_in_filter')
                    ->label('В фильтре'),
            ])
            ->defaultSort('sort_order')
            // Порядок характеристик задаёт и порядок групп в сетке карточки
            // (правило вехи 3.3): позиция группы выводится из минимального
            // sort_order её характеристик.
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип значения')
                    ->options(CarAttributeType::class),

                TernaryFilter::make('show_in_filter')
                    ->label('В фильтре каталога'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteCarAttributeAction::make(),
            ]);
        // Массового удаления нет намеренно: DeleteBulkAction не показал бы
        // числа затронутых автомобилей, а каскад здесь необратим.
    }
}
