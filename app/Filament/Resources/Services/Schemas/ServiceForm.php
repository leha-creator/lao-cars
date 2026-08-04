<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Позиция прайса: работа автосервиса или категория запчастей.
 */
final class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::mainSection(),
                self::priceSection(),
                self::publicationSection(),
            ]);
    }

    private static function mainSection(): Section
    {
        return Section::make('Основное')
            ->components([
                Select::make('category')
                    ->label('Категория')
                    ->options(ServiceCategory::class)
                    ->helperText('Запчасти уходят на свою посадочную страницу, остальные категории — блоками страницы автосервиса.')
                    ->required(),

                TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),

                // Не обязательное: трейт HasSlug заполнит пустой slug сам
                // и транслитерирует кириллицу. Проверка уникальности здесь,
                // а не только в БД, — чтобы администратор получил ошибку
                // валидации на форме, а не падение на уникальном индексе.
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Оставьте пустым — сгенерируется из названия.')
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z0-9-]+$/')
                    ->maxLength(255),

                Textarea::make('description')
                    ->label('Описание')
                    ->rows(4)
                    ->maxLength(2000),
            ]);
    }

    private static function priceSection(): Section
    {
        return Section::make('Цена')
            ->columns(2)
            ->components([
                // `live(onBlur: true)` — ради видимости уточнения ниже.
                // Без него поле «Уточнение» не появится, пока форма не
                // будет сохранена и открыта заново.
                TextInput::make('price')
                    ->label('Цена')
                    ->helperText('Пусто — «цена по запросу». Ноль означал бы «бесплатно».')
                    ->numeric()
                    ->prefix('₽')
                    ->live(onBlur: true),

                // «от» без суммы — не уточнение, а мусор в вёрстке прайса,
                // поэтому поле показывается только при заполненной цене.
                // Скрытый компонент не дегидрируется, значит очистка цены
                // заодно снимает и уточнение — ровно то, что нужно.
                //
                // Список подсказок собирается из фактических значений БД,
                // а не хардкодится: свободное поле развело бы «от» и «От»
                // как два разных уточнения.
                TextInput::make('price_note')
                    ->label('Уточнение к цене')
                    ->helperText('Например «от» или «за колесо».')
                    ->visible(fn (Get $get): bool => filled($get('price')))
                    ->datalist(self::priceNoteSuggestions())
                    ->maxLength(255),
            ]);
    }

    private static function publicationSection(): Section
    {
        return Section::make('Публикация')
            ->columns(2)
            ->components([
                Toggle::make('is_published')
                    ->label('Опубликовано')
                    ->default(true),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Порядок внутри категории; удобнее задавать перетаскиванием на вкладке категории.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }

    /**
     * Уже использованные уточнения плюс базовый набор сида.
     *
     * @return array<int, string>
     */
    private static function priceNoteSuggestions(): array
    {
        $existing = Service::query()
            ->whereNotNull('price_note')
            ->distinct()
            ->pluck('price_note')
            ->all();

        return array_values(array_unique([
            'от',
            'за колесо',
            'за сезон',
            'за 1 л',
            ...$existing,
        ]));
    }
}
