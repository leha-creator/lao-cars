<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceCategories\Actions;

use App\Models\ServiceCategory;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Удаление категории с понятной ошибкой вместо страницы 500
 * и вместо пустой посадочной страницы.
 *
 * Отдельный класс, а не метод таблицы, потому что точек удаления две —
 * строка списка и шапка страницы редактирования. Проверка, продублированная
 * в обеих, однажды обновится только в одной (правило `RULES.md`, прецедент
 * — `DeleteBrandAction`).
 *
 * Проверок здесь две, и отказы у них разного сорта:
 *
 * 1. За категорией числятся позиции. Внешний ключ
 *    `services.service_category_id` объявлен `restrictOnDelete()`, то есть
 *    без этой проверки БД бросит `QueryException`, и администратор увидит
 *    ошибку приложения вместо объяснения, что делать.
 *
 * 2. Это последняя категория страницы запчастей. База такое удаление
 *    разрешит молча, а посадочная страница подбора после него отдаст 200
 *    со вступлением и формой, но без единого блока — и заметить это
 *    снаружи некому.
 */
final class DeleteServiceCategoryAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (ServiceCategory $record, DeleteAction $action): void {
                if ($record->services()->exists()) {
                    Notification::make()
                        ->title('Категорию нельзя удалить')
                        ->body('За категорией числятся позиции — сначала перенесите или удалите их.')
                        ->danger()
                        ->send();

                    $action->cancel();

                    return;
                }

                if ($record->isOnlyPartsCategory()) {
                    Notification::make()
                        ->title('Категорию нельзя удалить')
                        ->body('Это последняя категория страницы «Запчасти» — страница подбора запчастей останется без единого блока. Сначала заведите другую категорию этой страницы.')
                        ->danger()
                        ->send();

                    $action->cancel();
                }
            });
    }
}
