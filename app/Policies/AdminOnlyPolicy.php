<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * База для разделов, доступных только администратору.
 *
 * Матрица прав проекта живёт в двух файлах — здесь и в `StaffPolicy`, —
 * а не расползается по семидесяти методам девяти политик, где расхождение
 * неизбежно. Конкретная политика — одна строка `extends`.
 *
 * Набор методов полный не для красоты: панель работает в строгом режиме
 * авторизации (`Panel::strictAuthorization()`), и отсутствующий метод
 * даёт `LogicException` в рантайме при первом же обращении. `reorder`
 * дёргают все `reorderable()`-таблицы, `deleteAny` — `DeleteBulkAction`.
 *
 * Отказы здесь намеренно не логируются: Filament зовёт `viewAny()`
 * у каждого ресурса на каждый рендер бокового меню, и лог превратился бы
 * в шум, в котором настоящий инцидент не найти. Логируются события
 * с последствиями — смена роли, сохранение настроек, модерация отзыва.
 */
abstract class AdminOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin();
    }
}
