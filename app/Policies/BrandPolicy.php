<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Справочник марок — часть каталога: без права заводить марку менеджер
 * не сможет добавить автомобиль, которого нет в сидах.
 *
 * @see StaffPolicy — матрица прав живёт в базовом классе
 */
final class BrandPolicy extends StaffPolicy {}
