<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Filament\Resources\Media\Tables\MediaPickerTable;
use App\Models\Media;
use Filament\Forms\Components\ModalTableSelect;

/**
 * Выбор изображения из медиабиблиотеки.
 *
 * Компонент, ради которого библиотека и делалась: веха 3.4 отложила его
 * дословно — «до появления первой связи», — и первая связь появилась
 * (`employees.media_id`, `reviews.media_id`).
 *
 * Работает в двух режимах:
 *
 * 1. со связью — `MediaPicker::make('media_id')->relationship('media', 'name')`
 *    (сотрудники и отзывы): запрос строит сам Filament по связи;
 * 2. без связи — `MediaPicker::make('home.promo.image_id')` (страница
 *    настроек, где Eloquent-модели у формы нет): выбранная запись ищется
 *    по id через `getSelectedRecordUsing()`, а запрос задаёт
 *    `MediaPickerTable`.
 *
 * Второй режим безопасен, и это проверено по коду пакета, а не
 * предположено: `Component::getModel()` объявлен `?string` и при
 * отсутствии модели возвращает `null`, а `TableSelectLivewireComponent`
 * читает своё свойство `model` только внутри ветки
 * `filled($relationshipName)`.
 *
 * Реализация одна на оба режима намеренно: разнести её по двум задачам
 * значило бы написать компонент дважды.
 */
final class MediaPicker extends ModalTableSelect
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->tableConfiguration(MediaPickerTable::class);
        $this->label('Изображение');
        $this->placeholder('Изображение не выбрано');

        // Режим без связи: найти уже выбранную запись по id. Со связью
        // Filament перезаписывает оба обработчика своими внутри
        // `relationship()` — она вызывается после `setUp()`, — поэтому
        // умолчания здесь первому режиму не мешают.
        $this->getSelectedRecordUsing(
            static fn (mixed $state): ?Media => filled($state) ? Media::find($state) : null,
        );

        // Подпись обязана быть задана, и это не косметика: свойство
        // `ModalTableSelect::$getOptionLabelUsing` объявлено без значения
        // по умолчанию и присваивается только внутри `relationship()`.
        // Без этой строки режим без связи падает на обращении к
        // неинициализированному типизированному свойству, а не показывает
        // сырой id.
        $this->getOptionLabelUsing(
            static fn (MediaPicker $component): ?string => $component->getSelectedRecord()?->name,
        );
    }
}
