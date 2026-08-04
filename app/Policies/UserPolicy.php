<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Раздача прав. Свою учётную запись пользователь правит на странице
 * профиля (`Panel::profile()`), и ролью этот доступ не ограничен —
 * править можно только себя.
 *
 * @see AdminOnlyPolicy — матрица прав живёт в базовом классе
 */
final class UserPolicy extends AdminOnlyPolicy {}
