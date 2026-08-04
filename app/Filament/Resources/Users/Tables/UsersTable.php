<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Actions\DeleteUserAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),

                // Подпись и цвет приходят из enum-а: хардкод здесь был бы
                // копией словаря, которая разъедется при третьей роли.
                TextColumn::make('role')
                    ->label('Роль')
                    ->badge()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->label('Роль')
                    ->options(UserRole::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteUserAction::make(),
            ]);
        // Массового удаления нет по той же причине, что у марок:
        // DeleteBulkAction удаляет строки мимо `before()`, то есть обошёл бы
        // проверку на последнего администратора целиком.
    }
}
