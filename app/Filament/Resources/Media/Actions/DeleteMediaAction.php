<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Actions;

use App\Models\Media;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Удаление из медиабиблиотеки с проверкой использования.
 *
 * Закрывает долг вехи 3.4, записанный в PHPDoc `MediaResource`
 * дословно. Потребители появились: `employees.media_id`,
 * `reviews.media_id` и ключ настроек `home.promo.image_id`.
 *
 * Блокировка, а не предупреждение. У сотрудников и отзывов есть
 * `nullOnDelete()` — они переживут удаление, потеряв фото. А вот ссылка
 * внутри jsonb настроек внешнего ключа не имеет: она превратится
 * в висячий id, который всплывёт вехой 4.2 пустым фоном на главной.
 *
 * Отдельный класс, потому что удаление доступно и из строки списка,
 * и из шапки страницы редактирования; голый `DeleteAction` во второй
 * молча обошёл бы проверку (правило `RULES.md`).
 */
final class DeleteMediaAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalDescription('Файл будет удалён с диска вместе с превью.')
            ->before(function (Media $record, DeleteAction $action): void {
                $usages = $record->usages();

                if ($usages === []) {
                    return;
                }

                Notification::make()
                    ->title('Изображение используется')
                    ->body('Сначала отвяжите его здесь: '.implode('; ', $usages).'.')
                    ->danger()
                    ->persistent()
                    ->send();

                $action->cancel();
            });
    }
}
