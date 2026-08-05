<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

/**
 * Форма заявки — один компонент на все формы сайта (веха 3.7).
 *
 * Три сценария из ТЗ различаются только параметрами: заявка на конкретный
 * автомобиль (`:source="$car"`), запись на услугу (`:source="$service"`)
 * и общая форма без источника. Подбор запчасти добавляет поля флагом
 * `:parts="true"`.
 *
 * Отдельных форм на страницу нет намеренно: три копии одной разметки
 * разъедутся на первом же изменении набора полей, а поле, добавленное
 * в две формы из трёх, теряет данные молча.
 */
final class LeadForm extends Component
{
    public function __construct(
        public ?Model $source = null,
        public string $title = 'Оставить заявку',
        public string $submit = 'Отправить заявку',
        public bool $parts = false,
    ) {}

    /**
     * Алиас источника для скрытого поля формы.
     *
     * `getMorphClass()` отдаёт готовые `car` и `service` — ровно то, что
     * лежит в `leads.source_type`, потому что morph map включён
     * в `AppServiceProvider::boot()`. Второй словарь «класс → алиас»
     * здесь не нужен и разошёлся бы с первым.
     */
    public function sourceType(): ?string
    {
        return $this->source?->getMorphClass();
    }

    public function render(): View
    {
        return view('components.lead-form');
    }
}
