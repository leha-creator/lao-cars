<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use App\Filament\Resources\Media\Actions\DeleteMediaAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

final class MediaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumb_url')
                    ->label('Превью')
                    ->square()
                    ->height(64),

                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('size')
                    ->label('Размер')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Загружено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteMediaAction::make(),
            ]);
        // Массового удаления здесь больше нет. В вехе 3.4 оно было
        // безопасно, потому что проверять было нечего: у библиотеки
        // не было потребителей, а файлы с диска убирались событием
        // `deleted`, которое `DeleteBulkAction` поднимает на каждой
        // записи. Теперь проверка есть, и она живёт в `before()`
        // строкового действия — а массовое удаление идёт мимо `before()`
        // и обошло бы её целиком. Та же причина, по которой массового
        // удаления нет у марок.
    }
}
