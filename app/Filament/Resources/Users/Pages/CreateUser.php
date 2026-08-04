<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Заведение сотрудника — событие с последствиями для безопасности,
     * поэтому пишется в лог. Пароль и e-mail в лог не попадают
     * (правило `rules/base.md`).
     */
    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        Log::info('Заведён пользователь панели', [
            'actor_id' => auth()->id(),
            'user_id' => $user->getKey(),
            'role' => $user->role->value,
        ]);
    }
}
