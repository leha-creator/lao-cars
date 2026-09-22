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

    /**
     * Цена, верхняя граница диапазона и уточнение — все три видны всегда.
     *
     * Раньше уточнение скрывалось при пустой цене, и это давало два
     * дефекта. При создании поля не было видно вовсе, пока фокус не уйдёт
     * с цены (форма перерисовывалась по `live(onBlur)`), — администратор
     * видел его только на редактировании. А на редактировании скрытое поле
     * Filament не дегидрирует: очистка цены оставляла уточнение в базе,
     * и оно воскресало при следующем вводе цены.
     *
     * «от» без суммы — по-прежнему мусор в вёрстке прайса, но теперь это
     * ошибка формы на цене (`requiredWith`), а не молчаливая потеря
     * введённого.
     */
    private static function priceSection(): Section
    {
        return Section::make('Цена')
            ->columns(2)
            ->components([
                TextInput::make('price')
                    ->label('Цена')
                    ->helperText('Пусто — «цена по запросу». Ноль означал бы «бесплатно».')
                    ->numeric()
                    ->prefix('₽')
                    ->requiredWith(['price_note', 'price_max'])
                    ->validationMessages([
                        'required_with' => 'Укажите цену: уточнение и «Цена до» без неё на сайт не выводятся.',
                    ]),

                TextInput::make('price_max')
                    ->label('Цена до')
                    ->helperText('Для диапазона: на сайте будет «от 6 500 до 9 000 ₽». Пусто — одна цена.')
                    ->numeric()
                    ->prefix('₽')
                    ->gt('price')
                    ->validationMessages([
                        'gt' => 'Верхняя граница должна быть больше цены.',
                    ]),

                // Список подсказок собирается из фактических значений БД,
                // а не хардкодится: свободное поле развело бы «от» и «От»
                // как два разных уточнения.
                TextInput::make('price_note')
                    ->label('Уточнение к цене')
                    ->helperText('Например «от» или «за колесо». «От» и «до» встают перед суммой, остальное — после.')
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
