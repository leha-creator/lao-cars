<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Услуги и категории запчастей — контент сайта, а не рабочий инструмент
 * менеджера: прайс и тексты страниц правит администратор.
 *
 * @see AdminOnlyPolicy — матрица прав живёт в базовом классе
 */
final class ServicePolicy extends AdminOnlyPolicy {}
