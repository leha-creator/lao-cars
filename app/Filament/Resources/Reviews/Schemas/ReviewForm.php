<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews\Schemas;

use App\Filament\Forms\Components\MediaPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::authorSection(),
                self::contentSection(),
                self::publicationSection(),
            ]);
    }

    private static function authorSection(): Section
    {
        return Section::make('Автор')
            ->columns(2)
            ->components([
                TextInput::make('author_name')
                    ->label('Имя')
                    ->required()
                    ->maxLength(255),

                TextInput::make('author_context')
                    ->label('Контекст')
                    ->helperText('Например «Клиент, импорт авто» — подпись под именем на карточке.')
                    ->maxLength(255),

                MediaPicker::make('media_id')
                    ->label('Фото автора')
                    ->relationship('media', 'name'),
            ]);
    }

    private static function contentSection(): Section
    {
        return Section::make('Отзыв')
            ->components([
                Textarea::make('body')
                    ->label('Текст')
                    ->rows(6)
                    ->required()
                    ->maxLength(5000),

                // Необязательный: в ТЗ рейтинг опционален, в макете он есть.
                ToggleButtons::make('rating')
                    ->label('Оценка')
                    ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])
                    ->inline(),
            ]);
    }

    private static function publicationSection(): Section
    {
        return Section::make('Публикация')
            ->columns(2)
            ->components([
                Toggle::make('is_published')
                    ->label('Опубликован'),

                // Только для чтения: правило первой публикации живёт
                // в `Review::booted()`, потому что публиковать можно ещё
                // и действием из списка, и из tinker.
                DateTimePicker::make('published_at')
                    ->label('Дата публикации')
                    ->helperText('Проставляется автоматически при первой публикации и дальше не меняется.')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Порядок отзывов на сайте; при равных значениях — по дате публикации.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }
}
