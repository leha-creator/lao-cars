<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Комментарии менеджера к заявке — неотделимы от самих заявок.
 *
 * @see LeadPolicy — политика написана до появления ресурса (веха 3.7)
 * @see StaffPolicy — матрица прав живёт в базовом классе
 */
final class LeadCommentPolicy extends StaffPolicy {}
