<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                // Не обязательное: трейт HasSlug заполнит пустой slug сам.
                // Проверка уникальности здесь, а не только в БД, — чтобы
                // администратор получил ошибку валидации на форме, а не
                // падение на уникальном индексе.
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Оставьте пустым — сгенерируется из названия.')
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z0-9-]+$/')
                    ->maxLength(255),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Порядок марок в фильтре каталога; при равных значениях — по алфавиту.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }
}
