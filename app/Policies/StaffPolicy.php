<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * База для разделов, доступных обеим ролям.
 *
 * Возвращает `true` без проверки роли, и это не заглушка: панель уже
 * закрыта аутентификацией (`Authenticate` в `authMiddleware`), а в
 * систему заводятся только сотрудники — таблица `users` не используется
 * для клиентов сайта. Каталог и заявки — прямая обязанность менеджера.
 *
 * Класс существует ради строгого режима авторизации: без политики
 * с полным набором методов Filament бросит `LogicException`. Заодно это
 * место, куда придётся вписать проверку, если у роли когда-нибудь
 * появятся частичные права внутри раздела.
 *
 * @see AdminOnlyPolicy — вторая половина матрицы прав проекта
 */
abstract class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return true;
    }

    public function delete(User $user): bool
    {
        return true;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function reorder(User $user): bool
    {
        return true;
    }
}
