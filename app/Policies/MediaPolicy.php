<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Медиабиблиотека — общий ресурс сайта: удалённый отсюда файл пропадает
 * со страниц, к каталогу отношения не имеющих. Фотографии автомобилей
 * менеджер грузит из карточки автомобиля, а не отсюда.
 *
 * @see AdminOnlyPolicy — матрица прав живёт в базовом классе
 */
final class MediaPolicy extends AdminOnlyPolicy {}
