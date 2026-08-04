<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

final class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Круглая — карточка команды в макете круглая.
                // Список грузится с `with('media')` (см. EmployeeResource):
                // без предзагрузки эта колонка даёт запрос на строку.
                ImageColumn::make('media.thumb_url')
                    ->label('Фото')
                    ->circular()
                    ->height(56),

                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('position')
                    ->label('Должность')
                    ->searchable(),

                // Ресурс целиком admin-only, поэтому обход политик
                // инлайн-колонкой здесь ничего не даёт.
                ToggleColumn::make('is_published')
                    ->label('На сайте'),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            // Порядок карточек на странице «О компании» задаётся
            // перетаскиванием: просить наполнителя проставлять числа
            // руками — гарантированные дубли и дыры в нумерации.
            ->reorderable('sort_order')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Изображение останется в медиабиблиотеке — файл переиспользуемый.'),
            ]);
    }
}
