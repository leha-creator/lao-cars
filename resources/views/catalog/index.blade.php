{{--
    Список каталога — вёрстка вехи 4.3 по макету `Catalog.dc.html`
    (строки 37–60 — заголовок и панель фильтров, 62–146 — сетка карточек,
    148–153 — пагинация) и `Catalog (mobile).dc.html` (строки 58–85).

    Вёрстка ложится на контракт вехи 3.6 и не переписывает его: имена
    GET-параметров, валидация, `noindex,follow` на комбинациях, canonical
    и 404 за последней страницей закрыты `CatalogFilterRequest`,
    `CatalogCriteria` и `CatalogController`. Тринадцать тестов
    `CatalogIndexTest` описывают это поведение, а не разметку, — покраснение
    любого из них означает, что вёрстка изобретает свой контракт вместо
    того, чтобы лечь на готовый.

    Панель верстается под перенос строки, а не под четыре селекта из макета:
    к фиксированным контролам добавляются динамические характеристики
    с флагом «в фильтре», и на засеянной базе их пять. Аккордеона «Ещё
    фильтры» здесь нет намеренно — спрятанный блок либо не виден роботу
    и посетителю без JS, либо моргает на каждой загрузке страницы. Триггер
    пересмотра: если панель перевалит за три ряда, фильтр переезжает
    в боковую колонку, а не прячется.
--}}
@extends('layouts.app')

@section('title', 'Каталог автомобилей — '.config('app.name'))
@section('description', 'Автомобили из Китая и Европы в наличии и под заказ: подбор по марке, типу двигателя, году и цене.')

@section('canonical')
    <link rel="canonical" href="{{ $canonical }}">
@endsection

@if ($filtered)
    {{-- Комбинация фильтров или неумолчательная сортировка — свой URL,
         которых сотни. В индекс идёт только чистый список и его страницы. --}}
    @section('robots', 'noindex,follow')
@endif

@section('content')
    @php
        $years = $options['years'];
        $yearRange = $years['min'] !== null && $years['max'] !== null
            ? range($years['max'], $years['min'])
            : [];

        // Границы цены уходят в placeholder: пустое поле «Цена от» ни о чём
        // не говорит, а «Цена от 980 000» сразу показывает, в каком диапазоне
        // вообще есть автомобили.
        $priceHint = fn (string $prefix, ?int $bound): string => $bound === null
            ? $prefix
            : $prefix.' '.number_format($bound, 0, ',', ' ');

        // Один и тот же набор классов у всех контролов панели: поле,
        // отличающееся от соседнего на пару пикселей, читается как
        // недоделка, а не как акцент.
        $control = 'w-full rounded-field border border-white/15 bg-surface px-4 py-3.5 text-sm transition-colors focus:border-accent/70 focus:outline-none lg:px-5';

        $pill = 'block cursor-pointer rounded-full border border-white/15 px-5 py-3 text-sm whitespace-nowrap text-ink-muted transition hover:border-accent/35 peer-checked:border-accent/50 peer-checked:text-accent peer-focus-visible:border-accent';
    @endphp

    <x-page-heading eyebrow="Каталог">
        Автомобили в наличии и <span class="text-accent">под заказ</span>
    </x-page-heading>

    {{--
        Форма охватывает и панель фильтров, и строку результатов с селектом
        сортировки. Отдельная форма под сортировку потребовала бы скрытых
        копий всех остальных полей — то есть второго места, где живут имена
        GET-параметров вехи 3.6.

        Скрытого поля `page` в форме нет, и это не упущение. GET-форма
        отправляет только свои поля, поэтому применение фильтра само
        возвращает на первую страницу. Соблазн «сохранить страницу» скрытым
        `<input name="page">` ломает не фильтрацию, а пользователя:
        контроллер вехи 3.6 отдаёт 404 за последней страницей, и человек,
        отфильтровавший выдачу со страницы 3, получил бы не пустой список,
        а ошибку.

        Форма остаётся обычной GET-формой, а Alpine добавляет ровно одно —
        авто-сабмит при изменении любого контрола. Правило записано
        в `ARCHITECTURE.md`: фильтры работают без JavaScript, потому что
        именно так их видит поисковый робот и именно так ссылка
        на отфильтрованную выдачу остаётся переносимой — открывается
        в другом браузере тем же результатом.

        Событие `change` всплывает, поэтому один обработчик на `<form>`
        покрывает и селекты, и радио-пилюли, и числовые поля. У последних
        `change` приходит по потере фокуса или Enter, а не на каждую
        набранную цифру, — это ожидаемо и правильно: перезагрузка после
        каждого символа в поле цены сделала бы фильтр неюзабельным.

        `x-data` пишется здесь инлайном, а не регистрируется через
        `Alpine.data()` в `@push('scripts')`, и ловушка неочевидная:
        `@vite` кладёт `app.js` модулем в `<head>`, модуль исполняется
        отложенно и сам вызывает `Alpine.start()`. Скрипт, добавленный
        в конец `<body>` через `@push`, исполнится либо раньше модуля
        (обычный `<script>` — `window.Alpine` ещё не существует), либо позже
        (`type="module"` — событие `alpine:init` уже отгремело, а после
        старта `Alpine.data()` к разобранной разметке не применяется).
        Оба варианта ломаются молча: разметка на месте, стили на месте,
        клики не работают.

        Кнопка «Показать» при живом авто-сабмите остаётся — см. комментарий
        у самой кнопки.

        Атрибут пишется полной формой `x-on:change`, а не сокращением
        `@change`: `@` — префикс директив Blade, и сокращение живёт
        в шаблоне только до тех пор, пока имя события не совпало с именем
        директивы. Так же оформлена шапка (`x-site-header`).
    --}}
    <form
        method="GET"
        action="{{ route('catalog.index') }}"
        x-data="{}"
        x-on:change="$el.requestSubmit()"
    >
        <section class="border-b border-white/10 px-5 py-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-page">
                <div class="flex flex-col gap-4 lg:flex-row lg:flex-wrap lg:items-center lg:gap-3.5">
                    {{--
                        Статус — радиокнопки внутри той же формы, а не ссылки,
                        хотя в макете они выглядят ссылками. Ссылка обязана
                        нести в `href` все остальные параметры, то есть склейку
                        текущего query прямо в шаблоне; радиокнопка несёт их
                        бесплатно, уходит тем же сабмитом и правильно сбрасывает
                        `page`.

                        На мобильном ряд прокручивается горизонтально
                        (макет мобильной версии), на десктопе уезжает вправо.
                        Группа одна на обе раскладки: вторая копия с тем же
                        `name` попала бы в ту же радиогруппу, и отмеченной
                        визуально оказалась бы только одна из двух.
                    --}}
                    <fieldset class="-mx-5 flex gap-2.5 overflow-x-auto px-5 lg:order-2 lg:mx-0 lg:ml-auto lg:overflow-visible lg:px-0">
                        <legend class="sr-only">Наличие</legend>

                        {{-- Вариантов три: «Продан» фильтром не выбирается —
                             это уже зафиксировано в `CatalogFilterRequest`,
                             а проданные из выдачи убирает скоуп available(). --}}
                        @foreach ([['', 'Все'], [\App\Enums\CarStatus::InStock->value, \App\Enums\CarStatus::InStock->label()], [\App\Enums\CarStatus::OnOrder->value, \App\Enums\CarStatus::OnOrder->label()]] as [$value, $label])
                            <label>
                                {{-- `sr-only`, а не `hidden`: спрятанный
                                     через `display:none` контрол недоступен
                                     с клавиатуры и не читается скринридером. --}}
                                <input
                                    type="radio"
                                    name="status"
                                    value="{{ $value }}"
                                    class="peer sr-only"
                                    @checked(($criteria->status?->value ?? '') === $value)
                                >
                                <span class="{{ $pill }}">{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    {{-- `lg:contents` растворяет обёртку на десктопе: контролы
                         становятся прямыми элементами флекса и переносятся
                         по одному, а на мобильном остаются сеткой в две
                         колонки. --}}
                    <div class="grid grid-cols-2 gap-2.5 lg:contents">
                        <label>
                            <span class="sr-only">Марка</span>
                            <select name="brand" class="{{ $control }}">
                                <option value="">Марка: все</option>
                                @foreach ($options['brands'] as $brand)
                                    <option value="{{ $brand->slug }}" @selected($criteria->brand === $brand->slug)>
                                        {{ $brand->name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="sr-only">Тип двигателя</span>
                            <select name="engine" class="{{ $control }}">
                                <option value="">Двигатель: любой</option>
                                @foreach ($options['engines'] as $engine)
                                    <option value="{{ $engine->value }}" @selected($criteria->engine === $engine)>
                                        {{ $engine->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="sr-only">Год от</span>
                            <select name="year_from" class="{{ $control }}">
                                <option value="">Год от: любой</option>
                                @foreach ($yearRange as $year)
                                    <option value="{{ $year }}" @selected($criteria->yearFrom === $year)>{{ $year }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="sr-only">Год до</span>
                            <select name="year_to" class="{{ $control }}">
                                <option value="">Год до: любой</option>
                                @foreach ($yearRange as $year)
                                    <option value="{{ $year }}" @selected($criteria->yearTo === $year)>{{ $year }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="sr-only">Цена от</span>
                            <input
                                type="number"
                                name="price_from"
                                min="0"
                                value="{{ $criteria->priceFrom }}"
                                placeholder="{{ $priceHint('Цена от', $options['prices']['min']) }}"
                                class="{{ $control }}"
                            >
                        </label>

                        <label>
                            <span class="sr-only">Цена до</span>
                            <input
                                type="number"
                                name="price_to"
                                min="0"
                                value="{{ $criteria->priceTo }}"
                                placeholder="{{ $priceHint('Цена до', $options['prices']['max']) }}"
                                class="{{ $control }}"
                            >
                        </label>

                        {{-- Динамические характеристики: список приходит
                             из `CatalogFilterOptions` уже отфильтрованным —
                             в нём только те значения, по которым что-то
                             найдётся. --}}
                        @foreach ($options['attributes'] as $item)
                            @php($attribute = $item['attribute'])
                            <label>
                                <span class="sr-only">{{ $attribute->label }}</span>
                                <select name="attr[{{ $attribute->key }}]" class="{{ $control }}">
                                    <option value="">{{ $attribute->label }}: любое</option>
                                    @foreach ($item['values'] as $value)
                                        <option
                                            value="{{ $value['value'] }}"
                                            @selected(($criteria->attributes[$attribute->key] ?? null) === $value['value'])
                                        >{{ $value['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 lg:mt-5">
                    {{-- Кнопка остаётся в разметке всегда, даже когда Alpine
                         отправляет форму сам (задача 4): она единственный путь
                         применить фильтр без JS, а кнопка, которая мигает
                         и исчезает через полсекунды после загрузки, хуже
                         кнопки лишней. --}}
                    <button
                        type="submit"
                        class="rounded-full bg-accent px-7 py-3 text-sm font-semibold text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                    >Показать</button>

                    @if ($filtered)
                        <a
                            href="{{ route('catalog.index') }}"
                            class="rounded-full border border-white/15 px-7 py-3 text-sm text-ink-muted transition hover:border-accent/35 hover:text-accent"
                        >Сбросить</a>
                    @endif
                </div>
            </div>
        </section>

        <div class="px-5 pt-10 lg:px-8 lg:pt-12">
            <div class="mx-auto flex max-w-page flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-ink-muted">Найдено: {{ $cars->total() }}</p>

                <label class="flex items-center gap-2.5">
                    <span class="sr-only">Сортировка</span>
                    <select name="sort" class="rounded-field border border-white/15 bg-surface px-4 py-2.5 text-sm transition-colors focus:border-accent/70 focus:outline-none">
                        @foreach (\App\Enums\CatalogSort::cases() as $sort)
                            <option value="{{ $sort->value }}" @selected($criteria->sort === $sort)>
                                {{ $sort->label() }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>
    </form>

    <section class="px-5 pt-8 pb-20 lg:px-8 lg:pt-10 lg:pb-28">
        <div class="mx-auto max-w-page">
            @if ($cars->isNotEmpty())
                {{-- Заголовок карточки — h2 по умолчанию, и это здесь верно:
                     своего h2 у секции нет, карточки идут прямо под h1. --}}
                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cars as $car)
                        <x-car-card :car="$car" />
                    @endforeach
                </div>

                {{ $cars->links('pagination.catalog') }}
            @else
                {{-- Осмысленный блок, а не строка текста: пустая выдача —
                     это тупик, и выход из него должен быть виден. --}}
                <div class="mx-auto max-w-xl rounded-card border border-white/10 bg-surface px-6 py-14 text-center lg:py-20">
                    <p class="font-display text-xl font-semibold">По этим условиям ничего не нашлось</p>
                    <p class="mt-3 text-sm leading-relaxed text-ink-muted">
                        Попробуйте расширить диапазон цены или года — или посмотрите весь каталог.
                    </p>

                    <a
                        href="{{ route('catalog.index') }}"
                        class="mt-7 inline-block rounded-full bg-accent px-7 py-3 text-sm font-semibold text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                    >Сбросить фильтры</a>
                </div>
            @endif
        </div>
    </section>
@endsection
