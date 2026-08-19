<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceCategories\Schemas;

use App\Enums\ServicePage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class ServiceCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                // Не обязательное: трейт HasSlug заполнит пустой slug сам
                // и транслитерирует кириллицу. Проверка уникальности здесь,
                // а не только в БД, — чтобы администратор получил ошибку
                // валидации на форме, а не падение на уникальном индексе.
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Оставьте пустым — сгенерируется из названия. Slug — это и якорь блока на странице: /services#detailing.')
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z0-9-]+$/')
                    ->maxLength(255),

                Select::make('page')
                    ->label('Страница')
                    ->options(ServicePage::class)
                    ->helperText('Автосервис — блок на странице услуг, Запчасти — карточка на посадочной странице подбора.')
                    ->default(ServicePage::Services)
                    ->required(),

                Textarea::make('description')
                    ->label('Описание')
                    ->helperText('Абзац под названием категории; пустое значение убирает абзац, но не блок.')
                    ->rows(3)
                    ->maxLength(2000),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Порядок блоков на странице; при равных значениях — по алфавиту.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }
}
