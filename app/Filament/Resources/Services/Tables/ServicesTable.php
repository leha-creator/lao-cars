<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceCategory;
use App\Filament\Resources\Services\Pages\ListServices;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Категория')
                    ->badge()
                    ->sortable(),

                TextColumn::make('price')
                    ->label('Цена')
                    ->money('RUB')
                    ->placeholder('по запросу')
                    ->sortable(),

                TextColumn::make('price_note')
                    ->label('Уточнение')
                    ->placeholder('—'),

                // Инлайн-колонка обходит политики (это задокументировано
                // в Filament: HasAuthorization, строки 14–22), но здесь это
                // безопасно — ресурс целиком admin-only, и обход проверки
                // ничего не даёт. У отзывов случай другой, там публикация
                // сделана действием с подтверждением.
                ToggleColumn::make('is_published')
                    ->label('Опубликовано'),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            // Пересортировка разрешена только внутри вкладки категории.
            // На вкладке «Все» первый же перетаск присвоил бы сквозные
            // номера и перемешал бы все блоки страницы автосервиса.
            ->reorderable(
                'sort_order',
                fn (ListServices $livewire): bool => $livewire->activeTab !== ListServices::ALL_TAB,
            )
            ->filters([
                SelectFilter::make('category')
                    ->label('Категория')
                    ->options(ServiceCategory::class),

                TernaryFilter::make('is_published')
                    ->label('Публикация')
                    ->placeholder('Все')
                    ->trueLabel('Опубликованные')
                    ->falseLabel('Скрытые'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }
}
