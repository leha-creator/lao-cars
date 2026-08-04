<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
                self::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Безопасно только потому, что `shouldFetchSelectedRecords`
                    // по умолчанию true и Filament удаляет записи по одной,
                    // поднимая событие `deleted`. Массовый запрос
                    // (`fetchSelectedRecords(false)`) событий не поднимает —
                    // включив его здесь, вы оставите файлы на диске навсегда.
                    DeleteBulkAction::make()
                        ->modalDescription('Файлы будут удалены с диска. Записи может использовать другой раздел сайта.'),
                ]),
            ]);
    }

    /**
     * Проверить использование записи в этой вехе нечем — потребители
     * библиотеки появляются вехой 3.5. Пока единственная страховка —
     * честное предупреждение в подтверждении.
     */
    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalDescription('Файл будет удалён с диска. Если на него уже ссылается раздел сайта, ссылка станет битой.');
    }
}
