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
 *
 * Раскладок у компонента две (веха 4.2), и это по тому же основанию
 * параметр, а не второй компонент: центрированная карточка на страницах
 * разделов и две колонки на фоновом фото на главной. Умолчания всех
 * четырёх новых параметров — `null`, поэтому вызовы вех 4.1/4.4/4.5
 * не меняются ни в одном шаблоне.
 *
 * Триггер пересмотра назван прямо: третий вызов с раскладкой — повод
 * вынести обёртку в отдельный компонент, оставив `x-lead-form` только
 * формой.
 */
final class LeadForm extends Component
{
    /**
     * @param  ?string  $eyebrow  Надзаголовок колонки с текстом (двухколоночная раскладка).
     * @param  ?string  $heading  Заголовок секции. Задан — раскладка двухколоночная, и заголовок
     *                            карточки при этом НЕ выводится: иначе два H2 подряд про одно
     *                            и то же. Иерархия заголовков не оформление — по ней ходят
     *                            скринридеры и по ней страницу разбирает поисковик.
     * @param  ?string  $text  Абзац под заголовком секции.
     * @param  ?string  $background  Готовый URL фонового изображения секции. Именно URL, а не путь:
     *                               компонент не должен знать про `Vite::asset()` — завтра фон
     *                               приедет из медиабиблиотеки, и менять придётся вызывающую
     *                               сторону, а не форму заявки.
     */
    public function __construct(
        public ?Model $source = null,
        public string $title = 'Оставить заявку',
        public string $submit = 'Отправить заявку',
        public bool $parts = false,
        public ?string $eyebrow = null,
        public ?string $heading = null,
        public ?string $text = null,
        public ?string $background = null,
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
