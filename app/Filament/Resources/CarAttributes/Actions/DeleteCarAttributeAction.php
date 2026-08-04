<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes\Actions;

use App\Models\CarAttribute;
use Filament\Actions\DeleteAction;

/**
 * Удаление характеристики с честным предупреждением о каскаде.
 *
 * Удаление уносит все значения этой характеристики у всех автомобилей —
 * это решение вехи 3.3 (`car_attribute_values.car_attribute_id` объявлен
 * `cascadeOnDelete()`). Без числа затронутых записей в подтверждении
 * администратор видит обычное «вы уверены?» и узнаёт цену задним числом.
 *
 * Отдельный класс, а не метод таблицы: точек удаления две — строка списка
 * и шапка страницы редактирования.
 */
final class DeleteCarAttributeAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalDescription(function (CarAttribute $record): string {
                $count = $record->values()->count();

                if ($count === 0) {
                    return 'Ни у одного автомобиля значение этой характеристики не заполнено. Действие необратимо.';
                }

                return "У {$count} автомобилей будет удалено значение этой характеристики. Действие необратимо.";
            });
    }
}
