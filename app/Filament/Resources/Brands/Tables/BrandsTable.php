<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Tables;

use App\Filament\Resources\Brands\Actions\DeleteBrandAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cars_count')
                    ->label('Автомобилей')
                    ->counts('cars')
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            // Порядок марок в фильтре каталога задаётся перетаскиванием:
            // просить наполнителя проставлять числа руками — гарантированные
            // дубли и дыры в нумерации.
            ->reorderable('sort_order')
            ->recordActions([
                EditAction::make(),
                DeleteBrandAction::make(),
            ]);
        // Массового удаления у марок нет намеренно: DeleteBulkAction удаляет
        // строки одним запросом мимо `before()`, то есть обошёл бы проверку
        // на связанные автомобили целиком.
    }
}
