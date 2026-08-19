<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceCategories\Tables;

use App\Enums\ServicePage;
use App\Filament\Resources\ServiceCategories\Actions\DeleteServiceCategoryAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ServiceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('page')
                    ->label('Страница')
                    ->badge()
                    ->sortable(),

                TextColumn::make('services_count')
                    ->label('Позиций')
                    ->counts('services')
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            // Порядок блоков на странице задаётся перетаскиванием: просить
            // наполнителя проставлять числа руками — гарантированные дубли
            // и дыры в нумерации.
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('page')
                    ->label('Страница')
                    ->options(ServicePage::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteServiceCategoryAction::make(),
            ]);
        // Массового удаления у категорий нет намеренно — по тому же
        // основанию, что у марок: DeleteBulkAction удаляет строки одним
        // запросом мимо `before()`, то есть обошёл бы ОБЕ проверки целиком.
    }
}
