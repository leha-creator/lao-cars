<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Actions;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Удаление пользователя с защитой от потери последнего администратора.
 *
 * Сценарий реальный, а не теоретический: администратор удаляет свою
 * учётную запись из списка. Панель после этого работает, войти в неё
 * можно, но настройки, пользователи и контент закрыты навсегда —
 * чинится только `laocars:make-admin` или руками в psql.
 *
 * Отдельный класс, а не метод таблицы, потому что точек удаления две —
 * строка списка и шапка страницы редактирования; голый `DeleteAction`
 * во второй молча обошёл бы проверку (правило `RULES.md`).
 */
final class DeleteUserAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (User $record, DeleteAction $action): void {
                if (! $record->isAdmin()) {
                    return;
                }

                $anotherAdminExists = User::query()
                    ->where('role', UserRole::Admin)
                    ->whereKeyNot($record->getKey())
                    ->exists();

                if ($anotherAdminExists) {
                    return;
                }

                Notification::make()
                    ->title('Это последний администратор')
                    ->body('Удалить его нельзя: панель осталась бы без доступа к настройкам и пользователям. Сначала назначьте администратором кого-то ещё.')
                    ->danger()
                    ->send();

                $action->cancel();
            });
    }
}
