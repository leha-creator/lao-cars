<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Общая обвязка для enum-ов с русскими подписями.
 *
 * Подписи живут в самом enum-е и больше нигде: без этого каждый select
 * в админке и каждый цикл в Blade заводят собственную копию словаря,
 * и они неизбежно расходятся.
 */
trait HasLabels
{
    abstract public function label(): string;

    /**
     * Подпись для Filament.
     *
     * Filament читает подписи только через собственный контракт
     * `Filament\Support\Contracts\HasLabel`: без него badge-колонка
     * покажет сырое значение, а `->options(EnumClass::class)` отдаст
     * имена кейсов (`InStock`) вместо русских подписей. Делегирование
     * живёт в трейте, а не в каждом enum-е, — иначе восемь копий одной
     * строки. Интерфейс объявляется на самих enum-ах: трейт объявить
     * `implements` не может.
     */
    public function getLabel(): ?string
    {
        return $this->label();
    }

    /**
     * Значения в виде «значение => подпись» — формат, который напрямую
     * принимают select-ы Filament и поля форм сайта.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
