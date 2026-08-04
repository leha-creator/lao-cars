<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Правятся только подписи: файл заменить нельзя.
 *
 * Подмена файла у существующей записи сломала бы всех, кто уже сослался
 * на её путь. Нужен другой файл — это новая запись библиотеки.
 */
final class MediaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->helperText('По нему изображение ищется в библиотеке.')
                    ->required()
                    ->maxLength(255),

                TextInput::make('alt')
                    ->label('Альтернативный текст')
                    ->helperText('Описание для поисковых систем и экранных читалок. Заменить сам файл нельзя — загрузите новый.')
                    ->maxLength(255),
            ]);
    }
}
