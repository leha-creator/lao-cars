<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Команда на странице «О компании» — контент сайта.
 *
 * @see AdminOnlyPolicy — матрица прав живёт в базовом классе
 */
final class EmployeePolicy extends AdminOnlyPolicy {}
