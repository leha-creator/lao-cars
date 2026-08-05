<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

/**
 * Секция заявки — обёртка вокруг `x-lead-form` (веха 4.3).
 *
 * Несёт две раскладки вехи 4.2: центрированная карточка на страницах
 * разделов и две колонки на фоновом фото на главной. Раскладки переехали
 * сюда из `x-lead-form` целиком, а не были переписаны: страницы `/`,
 * `/services`, `/parts` и `/contacts` обязаны выглядеть в точности так же.
 *
 * Разделение появилось не ради чистоты. Карточке автомобиля вехи 4.3 нужна
 * форма внутри правой колонки — то есть третья раскладка, а третья ветка
 * в компоненте с восемью параметрами и есть тот случай, который веха 4.2
 * назвала триггером пересмотра. Граница проходит по `<form>`: секция знает
 * про фон, колонки и заголовок, форма — про поля.
 *
 * Якорь `#lead-form` живёт здесь, на `<section>`, а не внутри `x-lead-form`.
 * На него ведут кнопка заявки в шапке (десктопной и мобильной) и умолчание
 * `home.promo.link_url`, поэтому он обязан быть на странице ровно один.
 * На `<form>` внутри компонента он дал бы два одинаковых `id` на первой же
 * странице, где секция и карточка авто встретятся вместе, — и кнопка
 * в шапке начала бы скроллить не туда.
 */
final class LeadSection extends Component
{
    /**
     * @param  string  $title  Заголовок карточки формы. Выводится, только пока не задан
     *                         `heading`: в двухколоночной раскладке заголовок секции уже
     *                         стоит в левой колонке, и дублировать его над формой значит
     *                         поставить два H2 подряд про одно и то же.
     * @param  ?string  $eyebrow  Надзаголовок колонки с текстом (двухколоночная раскладка).
     * @param  ?string  $heading  Заголовок секции. Задан — раскладка двухколоночная.
     * @param  ?string  $text  Абзац под заголовком секции.
     * @param  ?string  $background  Готовый URL фонового изображения секции. Именно URL, а не путь:
     *                               компонент не должен знать про `Vite::asset()` — завтра фон
     *                               приедет из медиабиблиотеки, и менять придётся вызывающую
     *                               сторону, а не секцию заявки.
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

    public function render(): View
    {
        return view('components.lead-section');
    }
}
