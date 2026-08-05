{{--
    Карточка автомобиля — вёрстка вехи 4.3 по макету `CarDetail.dc.html`
    (крошки — строка 36, две колонки — 40–120, похожие — 122–150).

    Контракт вехи 3.6 здесь не трогается: привязка по slug, доступность
    проданной карточки, состав сетки характеристик и подбор похожих
    закрыты `CatalogController`, `Car::cardAttributes()` и `SimilarCars`.
    Восемь тестов `CatalogShowTest` описывают это поведение, а не разметку.
--}}
@extends('layouts.app')

@section('title', $car->brand->name.' '.$car->model.', '.$car->year.' — '.config('app.name'))
@section('description', 'Купить '.$car->brand->name.' '.$car->model.' '.$car->year.' года в ЛАО КАРС.')

@use(App\Enums\CarStatus)

@section('content')
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

    <nav class="px-5 pt-8 lg:px-8 lg:pt-9" aria-label="Хлебные крошки">
        <ol class="mx-auto flex max-w-page flex-wrap gap-x-1.5 text-[13px] text-ink-faint">
            <li><a href="{{ route('catalog.index') }}" class="transition hover:text-accent">Каталог</a></li>
            {{-- Последний элемент — не ссылка: ссылка на текущую страницу
                 ничего не делает и сбивает клавиатурную навигацию. --}}
            <li aria-current="page"><span aria-hidden="true">/</span> {{ $name }}</li>
        </ol>
    </nav>

    <section class="px-5 pt-7 lg:px-8 lg:pt-8">
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
                             на хиро главной). Место резервирует контейнер
                             с `aspect-ratio`: реальных размеров файла проект
                             не хранит, поэтому `width`/`height` задать нечем.

                             `srcset` здесь тоже нет, и это осознанно:
                             дескриптор `w` обязан быть настоящей шириной файла,
                             а `ImageProcessor` ширину только ограничивает
                             сверху и не апскейлит — снимок 800px остаётся
                             800px, и дескриптор `1920w` на нём заставил бы
                             браузер выбрать заведомо не тот файл. --}}
                        <img
                            src="{{ $photo->url }}"
                            alt="{{ $photo->alt }}"
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
                        <div class="flex size-full items-center justify-center border border-white/10 bg-page-alt text-sm text-ink-faint">
                            Фотографии готовятся
                        </div>
                    @endforelse

                    @if ($car->photos->count() > 1)
                        <button
                            type="button"
                            x-cloak
                            x-on:click="active = (active + total - 1) % total"
                            aria-label="Предыдущее фото"
                            class="absolute top-1/2 left-4 flex size-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-page/70 text-lg backdrop-blur-sm transition hover:border-accent/50 hover:text-accent"
                        >‹</button>

                        <button
                            type="button"
                            x-cloak
                            x-on:click="active = (active + 1) % total"
                            aria-label="Следующее фото"
                            class="absolute top-1/2 right-4 flex size-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-page/70 text-lg backdrop-blur-sm transition hover:border-accent/50 hover:text-accent"
                        >›</button>

                        <div
                            x-cloak
                            x-text="(active + 1) + ' / ' + total"
                            class="absolute right-4 bottom-4 rounded-full border border-white/15 bg-page/70 px-3 py-1 text-xs text-ink-muted backdrop-blur-sm"
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
                {{-- Статус-пилюля в цвет статуса — тот же приём, что
                     в `x-car-card`: акцентная только для «в наличии». --}}
                <span
                    @class([
                        'mb-5 inline-block rounded-full border px-3 py-1.5 text-[11px] tracking-[0.08em] uppercase',
                        'border-accent/40 text-accent' => $car->status === CarStatus::InStock,
                        'border-white/20 text-ink-muted' => $car->status !== CarStatus::InStock,
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
                <div class="mb-8 grid grid-cols-2 gap-5 border-y border-white/10 py-7">
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
                <div id="lead-form" class="rounded-[20px] border border-white/8 bg-surface p-6 lg:p-8">
                    <x-lead-form :source="$car" title="Заявка на этот автомобиль" />
                </div>
            </div>
        </div>
    </section>

    @if ($similar->isNotEmpty())
        <section class="px-5 pt-20 pb-20 lg:px-8 lg:pt-25 lg:pb-28">
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
