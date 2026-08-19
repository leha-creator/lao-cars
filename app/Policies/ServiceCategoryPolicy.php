<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Справочник категорий услуг — контент сайта, а не рабочий инструмент
 * менеджера: состав блоков страницы правит администратор, как и сам прайс
 * (`ServicePolicy`).
 *
 * @see AdminOnlyPolicy — матрица прав живёт в базовом классе
 */
final class ServiceCategoryPolicy extends AdminOnlyPolicy {}
