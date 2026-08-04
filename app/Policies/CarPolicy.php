<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Каталог автомобилей — прямая обязанность менеджера.
 *
 * @see StaffPolicy — матрица прав живёт в базовом классе
 */
final class CarPolicy extends StaffPolicy {}
