<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Отзывы: публикация — то, что видит весь интернет, поэтому модерация
 * остаётся за администратором.
 *
 * @see AdminOnlyPolicy — матрица прав живёт в базовом классе
 */
final class ReviewPolicy extends AdminOnlyPolicy {}
