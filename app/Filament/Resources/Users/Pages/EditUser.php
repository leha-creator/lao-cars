<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Actions\DeleteUserAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Роль до сохранения.
     *
     * Снимок берётся именно в `beforeSave()`: `handleRecordUpdate()`
     * заполняет и сохраняет модель, после чего `getOriginal()` уже
     * синхронизирован с БД и разницы в нём не увидеть.
     */
    private ?UserRole $roleBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            // Именно защищённое действие: голый DeleteAction здесь позволил
            // бы удалить последнего администратора в обход проверки из списка.
            DeleteUserAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        $this->roleBeforeSave = $user->role;
    }

    /**
     * Смена роли — единственное событие этой вехи с последствиями для
     * безопасности, поэтому пишется в лог. Отказы политик не логируются
     * намеренно: Filament зовёт `viewAny()` у каждого ресурса на каждый
     * рендер меню, и лог превратился бы в шум.
     */
    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        if ($this->roleBeforeSave === $user->role) {
            return;
        }

        Log::info('Изменена роль пользователя панели', [
            'actor_id' => auth()->id(),
            'user_id' => $user->getKey(),
            'from' => $this->roleBeforeSave?->value,
            'to' => $user->role->value,
        ]);
    }
}
