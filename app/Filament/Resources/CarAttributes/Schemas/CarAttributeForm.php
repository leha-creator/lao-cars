<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes\Schemas;

use App\Enums\CarAttributeType;
use App\Models\CarAttribute;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class CarAttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::keyField(),

                TextInput::make('label')
                    ->label('Подпись')
                    ->helperText('Как характеристика называется в карточке и в фильтре.')
                    ->required()
                    ->maxLength(255),

                // `live()`, потому что от типа зависит видимость `unit` и `options`.
                Select::make('type')
                    ->label('Тип значения')
                    ->options(CarAttributeType::class)
                    ->required()
                    ->live(),

                TextInput::make('unit')
                    ->label('Единица измерения')
                    ->helperText('Подставляется после значения: «190 мм».')
                    ->maxLength(255)
                    // Ветвление идёт по методу enum-а, а не по строке 'number':
                    // `hasUnit()` написан в вехе 3.3 ровно под эту форму.
                    ->visible(fn (Get $get): bool => self::typeOf($get)?->hasUnit() === true),

                TagsInput::make('options')
                    ->label('Допустимые значения')
                    ->helperText('Варианты попадут в фильтр каталога — следите за регистром и опечатками.')
                    ->visible(fn (Get $get): bool => self::typeOf($get)?->hasOptions() === true)
                    ->required(fn (Get $get): bool => self::typeOf($get)?->hasOptions() === true),

                TextInput::make('group')
                    ->label('Группа в карточке')
                    ->helperText('Пусто — характеристика попадёт в общий блок без заголовка.')
                    ->maxLength(255)
                    // Свободное поле плодит «Импорт» и «импорт» как две секции
                    // карточки. Подсказка из уже заведённых групп это гасит,
                    // не запрещая завести новую.
                    ->datalist(fn (): array => CarAttribute::query()
                        ->whereNotNull('group')
                        ->distinct()
                        ->orderBy('group')
                        ->pluck('group')
                        ->all()),

                TextInput::make('sort_order')
                    ->label('Порядок')
                    ->helperText('Задаёт и порядок характеристик, и порядок групп в сетке карточки.')
                    ->numeric()
                    ->default(0)
                    ->required(),

                Toggle::make('show_in_card')
                    ->label('Показывать в карточке')
                    ->default(true),

                Toggle::make('show_in_filter')
                    ->label('Показывать в фильтре каталога')
                    ->default(false),
            ]);
    }

    /**
     * Машинное имя — публичный контракт, а не подпись.
     *
     * По нему строится GET-параметр фильтра каталога (веха 3.6) и обращение
     * из шаблона карточки (веха 4.3): `$car->attributeValue('body_type')`.
     * Правка ключа тихо ломает сохранённые ссылки и код шаблона, а на форме
     * выглядит безобидным переименованием — поэтому после создания поле не
     * просто `disabledOn('edit')`, но и не дегидрируется: одного `disabled()`
     * мало, Filament всё равно отправил бы поле, если бы оно попадало
     * в данные формы.
     */
    private static function keyField(): TextInput
    {
        return TextInput::make('key')
            ->label('Ключ')
            ->helperText('Латиница, snake_case. После сохранения не меняется — по нему строятся ссылки фильтра.')
            ->required()
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->rule('regex:/^[a-z][a-z0-9_]*$/')
            ->disabledOn('edit')
            ->dehydrated(fn (string $operation): bool => $operation === 'create');
    }

    /**
     * Текущий тип из состояния формы — или `null`, пока он не выбран.
     */
    private static function typeOf(Get $get): ?CarAttributeType
    {
        return CarAttributeType::tryFrom((string) $get('type'));
    }
}
