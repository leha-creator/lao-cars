<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use App\Models\Media;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Таблица медиабиблиотеки внутри модального окна выбора.
 *
 * Отдельный класс, а не переиспользованный `MediaTable`, по одной
 * конкретной причине: тот не задаёт запрос. В режиме без связи (страница
 * настроек, где Eloquent-модели у формы нет) подставить запрос в модальном
 * окне некому — `TableSelectLivewireComponent` вызывает `query()` только
 * внутри ветки `filled($relationshipName)`. Отсюда явный
 * `->query(Media::query())`.
 *
 * Действия строк модальное окно снимает само (`->recordActions([])`), но
 * полагаться на это, зная про массовое удаление в `MediaTable`, не стоит.
 *
 * @see MediaTable — таблица самого раздела медиабиблиотеки
 */
final class MediaPickerTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(Media::query())
            ->columns([
                ImageColumn::make('thumb_url')
                    ->label('Превью')
                    // `url()` обязателен: относительный адрес из аксессора
                    // Filament принял бы за путь на диске и отдал пустой
                    // `src` (см. `ImageColumn::getImageUrl()`).
                    ->state(fn (Media $record): string => url($record->thumb_url))
                    ->square()
                    ->height(64),

                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Загружено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
