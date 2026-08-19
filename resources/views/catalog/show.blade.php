{{--
    Карточка автомобиля — вёрстка вехи 4.3 по макету `CarDetail.dc.html`
    (крошки — строка 36, две колонки — 40–120, похожие — 122–150).

    Контракт вехи 3.6 здесь не трогается: привязка по slug, доступность
    проданной карточки, состав сетки характеристик и подбор похожих
    закрыты `CatalogController`, `Car::cardAttributes()` и `SimilarCars`.
    Восемь тестов `CatalogShowTest` описывают это поведение, а не разметку.
--}}
@extends('layouts.app')

{{--
    Meta-теги берутся из колонок `meta_title` и `meta_description`, которые
    веха 3.2 завела, а веха 3.4 научилась редактировать: до этой вехи
    у них не было ни одного потребителя — ровно та ситуация, в которой
    веха 4.2 застала ключи `seo.default_*`.

    Фолбэк живёт здесь, в шаблоне страницы, а не в layout, по тому же
    основанию, по которому веха 4.2 не стала класть туда `seo.default_*`:
    общий фолбэк в layout маскировал бы забытую секцию у остальных страниц —
    страница без `@section('title')` выглядела бы нормально, и заметить это
    было бы нечем.

    Проверка через `filled()`, а не `?:` и не `empty()` — правило `RULES.md`
    про строгую проверку пустоты действует и здесь, даже если `meta_title`
    со значением '0' абсурден.

    Канонический адрес отдельной секцией НЕ переопределяется: умолчание
    layout — `url()->current()`, то есть адрес без query-строки, а карточке
    нужен именно он. Секция «на всякий случай» здесь была бы копией
    умолчания, которая однажды разойдётся с оригиналом.
--}}
@section('title', filled($car->meta_title)
    ? $car->meta_title
    : $car->brand->name.' '.$car->model.', '.$car->year.' — '.config('app.name'))
@section('description', filled($car->meta_description)
    ? $car->meta_description
    : 'Купить '.$car->brand->name.' '.$car->model.' '.$car->year.' года в ЛАО КАРС.')

@use(App\Enums\CarStatus)

@section('content')
    {{--
        Единственное место вехи, где данные из базы попадают в HTML
        без экранирования Blade, — и потому единственное, где XSS вообще
        возможен. Внутри разметки лежит то, что администратор ввёл
        в админке: описание, история, значения характеристик. Строка
        закрывающий тег скрипта, вписанный администратором в описание,
        без `JSON_HEX_TAG` закрывает этот тег по-настоящему и превращает
        остаток страницы в разметку. Флаг переводит угловые скобки
        в юникодные экранирования внутри JSON, и такого закрытия
        не случается. Сторож на него стоит в `CarStructuredDataTest`:
        без теста флаг уберут при первом же рефакторинге как «лишний».

        `JSON_UNESCAPED_UNICODE` оставляет кириллицу кириллицей, а не
        столбцом экранированных кодов; `JSON_UNESCAPED_SLASHES` — адреса
        без экранированных косых черт. Оба — про читаемость исходника
        страницы, а не про поведение.
    --}}
    <script type="application/ld+json">@json($structuredData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

    @php
        $name = $car->brand->name.' '.$car->model.', '.$car->year;

        // Формулировки двигателя и пробега перенесены из функционального
        // шаблона вехи 3.6 как есть: они уже учитывают `hasVolume()`
        // (у электромобиля объёма нет вовсе) и `null` как «Новый» —
        // пробег null означает автомобиль под заказ, а не ноль километров.
        $engine = $car->engine_type->label()
            .($car->engine_type->hasVolume() && $car->engine_volume !== null ? ', '.$car->engine_volume.' л' : '')
            .($car->engine_power !== null ? ', '.$car->engine_power.' л.с.' : '');

        // Фиксированные характеристики идут первыми и всегда. Пустые
        // выпадают целиком: строка «Привод: —» ничего не сообщает.
        $fixed = array_filter([
            'Марка / модель' => $car->brand->name.' '.$car->model,
            'Год выпуска' => (string) $car->year,
            'Двигатель' => $engine,
            'Привод' => $car->drive?->label(),
            'Пробег' => $car->mileage !== null ? number_format($car->mileage, 0, ',', ' ').' км' : 'Новый',
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $groups = $car->cardAttributes();
    @endphp

    {{-- Карточка автомобиля светлая целиком, как и каталог, из которого
         на неё приходят (веха 4.11). Крошки — тоже: тёмная полоса над
         светлой страницей читалась бы как остаток шапки. --}}
    <nav class="theme-light px-5 pt-8 lg:px-8 lg:pt-9" aria-label="Хлебные крошки">
        <ol class="mx-auto flex max-w-page flex-wrap gap-x-1.5 text-[13px] text-ink-faint">
            <li><a href="{{ route('catalog.index') }}" class="transition hover:text-accent">Каталог</a></li>
            {{-- Последний элемент — не ссылка: ссылка на текущую страницу
                 ничего не делает и сбивает клавиатурную навигацию. --}}
            <li aria-current="page"><span aria-hidden="true">/</span> {{ $name }}</li>
        </ol>
    </nav>

    {{-- Нижнее поле секция берёт на себя только тогда, когда похожих
         автомобилей нет: обычно его даёт `pt-20` следующей секции. Без
         этой развилки светлая страница у автомобиля без похожих
         обрывалась бы вплотную к подвалу — до вехи 4.11 разрыва не было
         видно, потому что обе стороны стыка были одного цвета. --}}
    <section @class([
        'theme-light px-5 pt-7 lg:px-8 lg:pt-8',
        'pb-20 lg:pb-28' => $similar->isEmpty(),
    ])>
        <div class="mx-auto grid max-w-page gap-10 lg:grid-cols-[1.4fr_1fr] lg:gap-16">
            {{--
                Фотогалерея.

                Без JavaScript посетитель видит главное фото и сетку
                миниатюр, каждая из которых — настоящая ссылка
                на полноразмерный файл: это единственный способ посмотреть
                остальные снимки, когда скрипт не выполнился. Поэтому
                миниатюры — `<a href>`, а не `<button>`; Alpine перехватывает
                клик через `.prevent` и подменяет главное фото.

                Все снимки лежат в разметке стопкой, а показывается один.
                Соблазн свести галерею к одному `<img :src="…">` и списку
                путей в `x-data` короче ровно настолько, чтобы выглядеть
                улучшением, — и после такой правки в разметке остаётся одно
                изображение из семи: робот видит одно фото, посетитель
                без JS тоже. Ни один визуальный тест этого не покажет,
                поэтому сторож живёт в `CatalogShowTest`.

                Первый снимок рендерится без `x-show` вовсе — чтобы он
                существовал и без Alpine. Стрелки и счётчик, наоборот,
                существуют только для JS и несут `x-cloak`: без скрипта
                они ничего не делают, и показывать их значит обманывать.

                `x-data` инлайном, а не `Alpine.data()` из `@push('scripts')`
                — по причине, разобранной в `catalog/index.blade.php`
                и записанной правилом в `RULES.md`.
            --}}
            <div
                @if ($car->photos->count() > 1)
                    x-data="{ active: 0, total: {{ $car->photos->count() }} }"
                    x-on:keydown.left="active = (active + total - 1) % total"
                    x-on:keydown.right="active = (active + 1) % total"
                    tabindex="0"
                @endif
                class="focus-visible:outline-none"
            >
                <div class="relative mb-4 aspect-16/10 overflow-hidden rounded-card bg-photo lg:aspect-16/9">
                    @forelse ($car->photos as $index => $photo)
                        {{-- Главное фото — LCP этой страницы: `fetchpriority="high"`
                             и никакого `loading` (правило `RULES.md`, выведенное
                             на хиро главной).

                             `width`/`height` появились вехой 4.14 — таблица
                             наконец хранит реальные размеры. Атрибуты стоят
                             ИМЕННО ЗДЕСЬ, потому что здесь `src` — оригинал,
                             то есть тот самый файл, чьи размеры записаны;
                             на миниатюрах ниже `src` — превью другой ширины,
                             и те же числа были бы враньём. Пустые не пишутся
                             вовсе: у фотографий, залитых до вехи, размеров
                             нет, пока по ним не пройдёт `images:restamp`,
                             а `width="0"` схлопнул бы кадр.

                             Контейнер с `aspect-ratio` при этом ОСТАЁТСЯ:
                             он держит сетку ровной независимо от пропорций
                             файла — с вехи 4.14 кадр вписывается целиком,
                             и пропорции у него теперь любые.

                             `srcset` здесь по-прежнему нет, и это осознанно:
                             дескриптор `w` обязан быть настоящей шириной файла,
                             а `ImageProcessor` ширину только ограничивает
                             сверху и не апскейлит — снимок 800px остаётся
                             800px, и дескриптор `1920w` на нём заставил бы
                             браузер выбрать заведомо не тот файл. Хранить
                             ширину и апскейлить — разные вещи, и правило
                             `RULES.md` остаётся верным как есть. --}}
                        <img
                            src="{{ $photo->url }}"
                            alt="{{ $photo->alt }}"
                            @if ($photo->width !== null && $photo->height !== null)
                                width="{{ $photo->width }}"
                                height="{{ $photo->height }}"
                            @endif
                            @if ($index === 0)
                                fetchpriority="high"
                            @else
                                {{-- Остальные снимки до клика не нужны;
                                     `x-cloak` держит их скрытыми и без Alpine,
                                     а у первого его нет намеренно — иначе
                                     страница без JS осталась бы вовсе
                                     без фотографии. --}}
                                loading="lazy"
                                x-cloak
                            @endif
                            @if ($car->photos->count() > 1)
                                x-show="active === {{ $index }}"
                            @endif
                            class="absolute inset-0 size-full object-cover"
                        >
                    @empty
                        {{-- Пустой прямоугольник читается как недогруженная
                             страница, поэтому место фото занимает подпись. --}}
                        <div class="flex size-full items-center justify-center border border-line bg-page-alt text-sm text-ink-faint">
                            Фотографии готовятся
                        </div>
                    @endforelse

                    @if ($car->photos->count() > 1)
                        <button
                            type="button"
                            x-cloak
                            x-on:click="active = (active + total - 1) % total"
                            aria-label="Предыдущее фото"
                            class="absolute top-1/2 left-4 flex size-10 -translate-y-1/2 items-center justify-center rounded-full border border-line-strong bg-page/70 text-lg backdrop-blur-sm transition hover:border-accent/50 hover:text-accent"
                        >‹</button>

                        <button
                            type="button"
                            x-cloak
                            x-on:click="active = (active + 1) % total"
                            aria-label="Следующее фото"
                            class="absolute top-1/2 right-4 flex size-10 -translate-y-1/2 items-center justify-center rounded-full border border-line-strong bg-page/70 text-lg backdrop-blur-sm transition hover:border-accent/50 hover:text-accent"
                        >›</button>

                        <div
                            x-cloak
                            x-text="(active + 1) + ' / ' + total"
                            class="absolute right-4 bottom-4 rounded-full border border-line-strong bg-page/70 px-3 py-1 text-xs text-ink-muted backdrop-blur-sm"
                        ></div>
                    @endif
                </div>

                @if ($car->photos->count() > 1)
                    <div class="grid grid-cols-5 gap-3">
                        @foreach ($car->photos as $index => $photo)
                            <a
                                href="{{ $photo->url }}"
                                x-on:click.prevent="active = {{ $index }}"
                                class="aspect-16/9 overflow-hidden rounded-field bg-photo transition"
                                x-bind:class="active === {{ $index }} ? 'ring-2 ring-accent' : ''"
                            >
                                <img
                                    src="{{ $photo->thumb_url }}"
                                    alt="{{ $photo->alt }}"
                                    loading="lazy"
                                    class="size-full object-cover"
                                >
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                {{-- Статус-пилюля в цвет статуса — тот же приём и те же
                     три состояния, что в `x-car-card`: акцентная только
                     для «в наличии», «в пути» выделен яркостью обводки
                     и текста, остальные приглушены. Разъезд с карточкой
                     списка означал бы, что один и тот же автомобиль
                     выглядит по-разному в каталоге и на своей странице. --}}
                <span
                    @class([
                        'mb-5 inline-block rounded-full border px-3 py-1.5 text-[11px] tracking-[0.08em] uppercase',
                        'border-accent/40 text-accent' => $car->status === CarStatus::InStock,
                        'border-line-loud text-ink' => $car->status === CarStatus::InTransit,
                        'border-line-strong text-ink-muted' => ! in_array($car->status, [CarStatus::InStock, CarStatus::InTransit], true),
                    ])
                >{{ $car->status->label() }}</span>

                <h1 class="mb-4 font-display text-[28px] font-semibold lg:text-[36px]">{{ $name }}</h1>

                <div class="mb-8 font-display text-2xl font-semibold text-accent lg:text-[32px]">
                    @if ($car->price !== null)
                        {{ number_format((int) $car->price, 0, ',', ' ') }} ₽
                    @else
                        Цена по запросу
                    @endif
                </div>

                {{--
                    Сетка одна на фиксированные и динамические характеристики,
                    группы — подзаголовками во всю ширину. Разносить их
                    по разным блокам отклонено: для посетителя «Кузов»
                    и «Привод» — одна и та же таблица, а разделение выдало бы
                    внутреннее устройство базы.

                    Разметка — сетка из `<div>`, а не `<dl>`: заголовок группы
                    внутри списка определений невалиден, а выносить его наружу
                    значит разбить одну сетку на несколько.
                --}}
                <div class="mb-8 grid grid-cols-2 gap-5 border-y border-line py-7">
                    @foreach ($fixed as $label => $value)
                        <div>
                            <div class="mb-1.5 text-xs text-ink-faint">{{ $label }}</div>
                            <div class="text-[15px]">{{ $value }}</div>
                        </div>
                    @endforeach

                    @foreach ($groups as $group => $values)
                        {{-- Безымянная группа выводится без заголовка. Ключ —
                             '', а не null: PHP приводит null к пустой строке
                             (PHPDoc `Car::cardAttributes()`), и модель уже
                             переставила эту группу в конец. --}}
                        @if ($group !== '')
                            <h2 class="col-span-full mt-1 text-[13px] tracking-[0.1em] text-ink-faint uppercase">{{ $group }}</h2>
                        @endif

                        @foreach ($values as $value)
                            <div>
                                <div class="mb-1.5 text-xs text-ink-faint">{{ $value->attribute->label }}</div>
                                <div class="text-[15px]">{{ $value->formatted }}</div>
                            </div>
                        @endforeach
                    @endforeach
                </div>

                {{-- История и описание — только при непустом значении,
                     проверка строгая (правило `RULES.md`): `empty()`
                     истинно и для строки '0'. --}}
                @if ($car->history !== null && $car->history !== '')
                    <div class="mb-8">
                        <h2 class="mb-2.5 text-[15px] font-semibold">История автомобиля</h2>
                        <p class="text-sm leading-[1.7] whitespace-pre-line text-ink-muted">{{ $car->history }}</p>
                    </div>
                @endif

                @if ($car->description !== null && $car->description !== '')
                    <div class="mb-8">
                        <h2 class="mb-2.5 text-[15px] font-semibold">Описание</h2>
                        <p class="text-sm leading-[1.7] whitespace-pre-line text-ink-muted">{{ $car->description }}</p>
                    </div>
                @endif

                {{--
                    Форма вызывается напрямую, без `x-lead-section`: секция
                    несёт свои поля и фон во всю ширину, а здесь форма стоит
                    внутри правой колонки — это и есть та третья раскладка,
                    ради которой веха 4.3 разделила компонент.

                    Якорь `#lead-form` — на этой карточке: он цель кнопки
                    «Оставить заявку» в шапке и обязан быть на странице ровно
                    один. Источник заявки — сам автомобиль: в списке лидов
                    менеджер увидит «Авто: <марка> <модель>», а не «Общая
                    форма».
                --}}
                <div id="lead-form" class="rounded-[20px] border border-line bg-surface p-6 lg:p-8">
                    <x-lead-form :source="$car" title="Заявка на этот автомобиль" />
                </div>
            </div>
        </div>
    </section>

    @if ($similar->isNotEmpty())
        <section class="theme-light px-5 pt-20 pb-20 lg:px-8 lg:pt-25 lg:pb-28">
            <div class="mx-auto max-w-page">
                <h2 class="mb-8 font-display text-2xl font-semibold lg:mb-10 lg:text-[28px]">
                    Похожие <span class="text-accent">автомобили</span>
                </h2>

                <div class="grid gap-8 sm:grid-cols-3">
                    @foreach ($similar as $item)
                        {{-- Тот же `x-car-card`, что и в списке каталога.
                             В макете карточка похожего упрощена до фото,
                             названия и цены, но третьей копии одной разметки
                             ради этой разницы не заводится — предупреждение
                             стоит в самом компоненте.

                             Карточка вложена в раздел со своим h2, поэтому
                             её заголовок — h3. --}}
                        <x-car-card :car="$item" heading="h3" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
