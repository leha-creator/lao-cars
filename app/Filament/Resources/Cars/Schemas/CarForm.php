<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars\Schemas;

use App\Enums\CarAttributeType;
use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Services\ImageProcessor;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Карточка автомобиля.
 *
 * Разложена секциями осознанно: полей больше двадцати, и сплошным
 * списком форма нечитаема — наполнитель теряет строку, в которую
 * собирался вписать цену.
 */
final class CarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::mainSection(),
                self::technicalSection(),
                self::priceSection(),
                self::photosSection(),
                self::attributesSection(),
                self::textsSection(),
                self::seoSection(),
            ]);
    }

    /**
     * Галерея: выбор пачкой и порядок перетаскиванием.
     *
     * `FileUpload::multiple()`, а не `Repeater` со связью. У `Repeater`
     * есть `relationship()` и поле `alt` на каждое фото, но файлы в него
     * грузятся по одному: администратор с восемью снимками делает восемь
     * заходов «Добавить → выбрать файл». Мультизагрузка — прямое
     * требование роадмапа, поэтому `alt` генерируется автоматически по
     * образцу `CarPhotoSeeder`. Для SEO это равноценно: осмысленный
     * автоматический alt лучше пустого, а вручную заполнять восемь полей
     * на карточку никто не будет.
     */
    private static function photosSection(): Section
    {
        return Section::make('Фотографии')
            ->description('Первое фото — главное. Порядок задаётся перетаскиванием.')
            ->components([
                FileUpload::make('photos')
                    ->hiddenLabel()
                    ->multiple()
                    ->reorderable()
                    // Без appendFiles() новая пачка заменяет уже
                    // загруженные файлы вместо того, чтобы дополнить их.
                    ->appendFiles()
                    ->image()
                    ->imageEditor()
                    ->panelLayout('grid')
                    ->disk('public')
                    ->directory('cars')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize((int) config('images.max_upload_kb'))
                    ->saveUploadedFileUsing(
                        fn (TemporaryUploadedFile $file, ImageProcessor $processor): string => $processor
                            ->store($file, 'public', 'cars')
                            ->path,
                    )
                    ->formatStateUsing(
                        fn (?Car $record): array => $record?->photos->pluck('path')->all() ?? [],
                    ),
            ]);
    }

    private static function mainSection(): Section
    {
        return Section::make('Основное')
            ->columns(2)
            ->components([
                Select::make('brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    // Новая марка заводится не выходя из карточки: иначе
                    // наполнение упирается в переход в другой раздел
                    // и обратно.
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                    ]),

                TextInput::make('model')
                    ->label('Модель')
                    ->required()
                    ->maxLength(255),

                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Часть публичного адреса карточки. Пусто — сгенерируется. Менять у опубликованного автомобиля — терять ссылки и позиции в поиске.')
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z0-9-]+$/')
                    ->maxLength(255),

                TextInput::make('year')
                    ->label('Год выпуска')
                    ->numeric()
                    ->required()
                    ->minValue(1950)
                    ->maxValue((int) date('Y') + 1),
            ]);
    }

    private static function technicalSection(): Section
    {
        return Section::make('Технические характеристики')
            ->columns(2)
            ->components([
                Select::make('engine_type')
                    ->label('Двигатель')
                    ->options(EngineType::class)
                    ->required(),

                TextInput::make('engine_volume')
                    ->label('Объём двигателя')
                    ->numeric()
                    ->step(0.1)
                    ->suffix('л'),

                TextInput::make('engine_power')
                    ->label('Мощность')
                    ->numeric()
                    ->suffix('л.с.'),

                Select::make('drive')
                    ->label('Привод')
                    ->options(DriveType::class)
                    ->required(),

                // `null` здесь означает «новый автомобиль» — это решение
                // вехи 3.2, а не пропущенное поле.
                TextInput::make('mileage')
                    ->label('Пробег')
                    ->helperText('Пусто — новый автомобиль.')
                    ->numeric()
                    ->suffix('км'),
            ]);
    }

    private static function priceSection(): Section
    {
        return Section::make('Цена и статус')
            ->columns(2)
            ->components([
                TextInput::make('price')
                    ->label('Цена')
                    ->helperText('Пусто — «цена по запросу». Ноль означал бы «бесплатно».')
                    ->numeric()
                    ->prefix('₽'),

                Select::make('status')
                    ->label('Статус')
                    ->options(CarStatus::class)
                    ->default(CarStatus::InStock->value)
                    ->required(),

                Toggle::make('show_on_homepage')
                    ->label('Показывать на главной'),

                TextInput::make('sort_order')
                    ->label('Порядок на главной')
                    ->helperText('Порядок в подборке на главной; удобнее задавать перетаскиванием в списке.')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }

    /**
     * Значения динамических характеристик.
     *
     * Состав секции строится из справочника на рендере, а не описывается
     * руками, — в этом весь смысл вехи 3.3: администратор добавляет
     * строку в «Характеристики авто» и немедленно видит поле здесь,
     * без разработчика и без миграции.
     *
     * Состояние живёт под ключом `car_attributes`, а не `attributes`:
     * `$car->fill(['attributes' => [...]])` попал бы в служебное свойство
     * Eloquent и дал бы диагностически бесполезную ошибку.
     */
    private static function attributesSection(): Section
    {
        return Section::make('Характеристики')
            // Пустой справочник — валидный случай: сид мог не отработать.
            // Пустая рамка в форме объясняет меньше, чем её отсутствие.
            ->visible(fn (): bool => CarAttribute::query()->exists())
            ->components(fn (): array => self::attributeFields());
    }

    /**
     * @return array<int, Fieldset>
     */
    private static function attributeFields(): array
    {
        $groups = CarAttribute::query()
            ->ordered()
            ->get()
            ->groupBy(fn (CarAttribute $attribute): string => $attribute->group ?? '');

        // Безымянная группа уходит в конец: без заголовка она читается
        // как хвост секции, а не как блок посреди неё. То же правило,
        // что в `Car::cardAttributes()`.
        if ($groups->count() > 1 && $groups->has('')) {
            $ungrouped = $groups->get('');
            $groups->forget('');
            $groups->put('', $ungrouped);
        }

        $fieldsets = [];

        foreach ($groups as $group => $attributes) {
            $fields = $attributes
                ->map(fn (CarAttribute $attribute): Field => self::attributeField($attribute))
                ->all();

            $fieldsets[] = $group === ''
                ? Fieldset::make('Прочее')->columns(2)->components($fields)
                : Fieldset::make((string) $group)->columns(2)->components($fields);
        }

        return $fieldsets;
    }

    /**
     * Компонент под тип характеристики.
     *
     * Ветвление идёт по `CarAttributeType`, а не по строкам: методы
     * `hasUnit()` и `hasOptions()` для того и написаны в вехе 3.3.
     */
    private static function attributeField(CarAttribute $attribute): Field
    {
        $name = "car_attributes.{$attribute->key}";

        return match ($attribute->type) {
            CarAttributeType::Boolean => Toggle::make($name)->label($attribute->label),

            CarAttributeType::Select => Select::make($name)
                ->label($attribute->label)
                ->options(array_combine($attribute->options ?? [], $attribute->options ?? []))
                ->searchable(count($attribute->options ?? []) > 10),

            // Осознанно без `numeric()`: тот вешает правило `numeric`,
            // а оно проверяет сырое состояние — до `dehydrateStateUsing()`.
            // Введённое «2,5» отвалилось бы с «поле должно быть числом»,
            // так и не дойдя до нормализации. Собственное правило
            // принимает оба разделителя, `inputMode` оставляет на телефоне
            // цифровую клавиатуру.
            CarAttributeType::Number => TextInput::make($name)
                ->label($attribute->label)
                ->inputMode('decimal')
                ->suffix($attribute->unit)
                ->rule('regex:/^-?\d+([.,]\d+)?$/')
                // `CarAttributeType::cast()` и `Car::normalizeAttributeValue()`
                // прямо документируют: разделитель — точка, а запятую
                // обязана нормализовать форма, потому что `(float) '2,5'`
                // в PHP молча даёт `2.0`. Это и есть та самая форма.
                ->dehydrateStateUsing(
                    fn (mixed $state): mixed => is_string($state) ? str_replace(',', '.', $state) : $state,
                ),

            CarAttributeType::Text => TextInput::make($name)
                ->label($attribute->label)
                ->maxLength(255),
        };
    }

    private static function textsSection(): Section
    {
        return Section::make('Тексты')
            ->components([
                // Textarea, а не RichEditor: `description` уходит
                // в микроразметку вехи 4.3, и HTML внутри неё её ломает.
                Textarea::make('history')
                    ->label('История автомобиля')
                    ->rows(4),

                Textarea::make('description')
                    ->label('Описание')
                    ->rows(6),
            ]);
    }

    private static function seoSection(): Section
    {
        return Section::make('SEO')
            ->collapsed()
            ->components([
                TextInput::make('meta_title')
                    ->label('Meta title')
                    ->helperText('Пусто — подставятся значения по умолчанию из настроек сайта (веха 3.5).')
                    ->maxLength(255),

                TextInput::make('meta_description')
                    ->label('Meta description')
                    ->maxLength(255),
            ]);
    }
}
