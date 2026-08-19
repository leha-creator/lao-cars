<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Forms\Components\MediaPicker;
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
                self::appearanceSection(),
                self::priceSection(),
                self::publicationSection(),
            ]);
    }

    private static function mainSection(): Section
    {
        return Section::make('Основное')
            ->components([
                // `preload()` уместен: категорий единицы, не тысячи.
                Select::make('service_category_id')
                    ->label('Категория')
                    ->relationship('category', 'name')
                    ->preload()
                    ->searchable()
                    ->helperText('Страница, на которой выводится позиция, задаётся у самой категории.')
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

                // Короткое описание. Уточнение в подсказке не косметика:
                // без него два текстовых поля рядом неразличимы, и длинный
                // текст уедет в то, что выше.
                Textarea::make('description')
                    ->label('Описание')
                    ->helperText('Одна-две строки под названием. Длинный текст — в «Подробное описание».')
                    ->rows(4)
                    ->maxLength(2000),
            ]);
    }

    private static function appearanceSection(): Section
    {
        return Section::make('Оформление')
            ->components([
                // Режим со связью — тот же вызов, что у сотрудников
                // и отзывов.
                MediaPicker::make('media_id')
                    ->relationship('media', 'name')
                    ->label('Фотография')
                    ->helperText('Позиции с фотографией выводятся карточками выше строк прайса. Без фотографии позиция остаётся строкой — это штатно.'),

                Toggle::make('is_featured')
                    ->label('Акцентная')
                    ->helperText('Широкая карточка во всю ширину контента с фотографией на фоне. Без фотографии карточка остаётся широкой, но обычной.'),

                Textarea::make('details')
                    ->label('Подробное описание')
                    ->helperText('Раскрывается по кнопке «Подробнее» под карточкой. Пустое — кнопки нет.')
                    ->rows(8)
                    ->maxLength(5000),
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

                // Про группы сказано прямо, и это не косметика: перетаскивание
                // строки без фотографии выше строки с фотографией не изменит
                // на сайте ничего — администратор потянет, отпустит, увидит
                // новый порядок в таблице и решит, что сделал.
                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Порядок действует внутри группы: сначала акцентные позиции, затем позиции с фотографией, затем остальные. Удобнее задавать перетаскиванием на вкладке категории.')
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
