<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Общая обвязка для enum-ов с цветом бейджа в админке.
 *
 * Зеркало `HasLabels` и по той же причине: Filament читает цвет только
 * через собственный контракт `Filament\Support\Contracts\HasColor`,
 * а хардкод цвета в badge-колонке ресурса — копия словаря, которая
 * неизбежно разъезжается с оригиналом при первом же новом статусе.
 *
 * Интерфейс объявляется на самих enum-ах: трейт объявить `implements`
 * не может.
 */
trait HasColors
{
    abstract public function color(): string;

    public function getColor(): string
    {
        return $this->color();
    }
}
