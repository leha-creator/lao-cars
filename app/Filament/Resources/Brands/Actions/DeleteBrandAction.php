<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Actions;

use App\Models\Brand;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Удаление марки с понятной ошибкой вместо страницы 500.
 *
 * Внешний ключ `cars.brand_id` объявлен `restrictOnDelete()`: без этой
 * проверки БД бросит `QueryException`, и администратор увидит ошибку
 * приложения вместо объяснения, что делать.
 *
 * Отдельный класс, а не метод таблицы, потому что точек удаления две —
 * строка списка и шапка страницы редактирования. Проверка, продублированная
 * в обеих, однажды обновится только в одной.
 */
final class DeleteBrandAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (Brand $record, DeleteAction $action): void {
                if (! $record->cars()->exists()) {
                    return;
                }

                Notification::make()
                    ->title('Марку нельзя удалить')
                    ->body('За маркой числятся автомобили — сначала перенесите или удалите их.')
                    ->danger()
                    ->send();

                $action->cancel();
            });
    }
}
