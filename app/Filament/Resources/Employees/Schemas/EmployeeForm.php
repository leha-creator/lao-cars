<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use App\Filament\Forms\Components\MediaPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Имя')
                    ->required()
                    ->maxLength(255),

                TextInput::make('position')
                    ->label('Должность')
                    ->required()
                    ->maxLength(255),

                Textarea::make('bio')
                    ->label('О сотруднике')
                    ->rows(4)
                    ->maxLength(2000),

                // Первый потребитель медиабиблиотеки — ради него веха 3.4
                // и откладывала пикер до появления связи.
                MediaPicker::make('media_id')
                    ->label('Фото')
                    ->relationship('media', 'name'),

                Toggle::make('is_published')
                    ->label('Показывать на сайте')
                    ->default(true),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Порядок карточек на странице «О компании»; удобнее задавать перетаскиванием в списке.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }
}
