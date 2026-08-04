<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Справочник характеристик — часть каталога по той же причине, что и
 * марки: карточку автомобиля без него не заполнить.
 *
 * @see StaffPolicy — матрица прав живёт в базовом классе
 */
final class CarAttributePolicy extends StaffPolicy {}
