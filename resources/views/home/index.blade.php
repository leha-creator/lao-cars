{{--
    Главная (веха 4.2).

    Данные собирает `App\Services\HomeContent`: подборка авто с отметкой
    «показывать на главной», бегущая строка, промо-баннер, преимущества
    и SEO-заголовки. Блоки, управляемые настройками, при пустом значении
    не рендерятся вовсе — заголовок над пустой сеткой читается как поломка,
    а не как «мало контента».

    Порядок блоков — из макета: бегущая строка → хиро → промо → подборка
    авто → преимущества → направления → заявка.
--}}
@extends('layouts.app')

{{--
    Прозрачная шапка — состояние представления, а не данные, поэтому флаг
    ставит шаблон, а не контроллер.

    Строка обязана стоять на верхнем уровне файла, до секций: `@extends`
    компилируется в вызов с `get_defined_vars()` в конец шаблона, и до
    layout доезжают только переменные верхнего уровня. Перенесённая внутрь
    `@section` или в `@push`, она тихо перестанет работать — шапка снова
    станет непрозрачной, а искать будут в компоненте.
--}}
@php $headerOverlay = true; @endphp

{{--
    SEO-заголовки по умолчанию (веха 3.5) получают здесь первого потребителя:
    у главной `title` и есть «заголовок сайта по умолчанию».

    Фолбэк стоит в шаблоне, а не в layout. Общий фолбэк в layout полез бы
    в БД в обход правила «Blade не ходит в БД» и, что важнее, маскировал бы
    забытую секцию у каталога, карточки и разделов — у них свои осмысленные
    заголовки. Пустая настройка даёт строку отсюда, а не пустой тег.
--}}
@section('title', $seo['title'] ?? config('app.name').' — импорт автомобилей и автосервис')
@section('description', $seo['description'] ?? 'Импорт автомобилей из Китая и Европы под ключ, собственный автосервис, детейлинг и подбор запчастей в Москве.')

{{--
    Бегущая строка над шапкой. Пустой `home.ticker` не даёт секции вовсе —
    `@yield` без содержимого не рендерит ничего.

    `overflow-hidden` на контейнере — не косметика: лента заведомо шире
    экрана, и без него горизонтальную прокрутку получает вся страница
    на всю высоту. Заметнее всего это на мобильном, где выглядит
    как сломанная вёрстка целиком, хотя причина в одном классе.
--}}
@section('above-header')
    @if ($ticker !== [])
        {{-- Высота полосы — 8px на мобильном и 9px на десктопе (макет).
             Девять задано произвольным значением, а не `py-2.25`: шкала
             отступов Tailwind знает только шаги в половину, и `py-2.25`
             не порождает правила вовсе — класс в разметке есть, CSS нет,
             отступ молча не тот. --}}
        <div class="overflow-hidden border-b border-white/6 bg-deep py-2 lg:py-[9px]">
            {{--
                Лента едет `translateX(-50%)`, поэтому копий обязано быть
                ровно две и они обязаны совпадать. Копии порождает цикл,
                а не две записанные подряд группы: так совпадение — свойство
                разметки, а не дисциплины того, кто правит текст.

                Зазор между копиями задаёт правый отступ группы, а НЕ `gap`
                на обёртке. В десктопном макете стоит и то, и другое —
                при таком наборе `-50%` не равно ширине одной группы, и лента
                дёргается на пол-зазора каждый цикл. Мобильный макет сделан
                правильно, взят его вариант. Ошибка видна только на длинном
                просмотре, поэтому снята здесь, а не после жалобы.
            --}}
            <div class="flex w-max animate-marquee">
                @foreach ([false, true] as $isCopy)
                    {{-- Вторая копия скрыта от скринридера: иначе он читает
                         все тезисы дважды подряд. --}}
                    <div class="flex gap-10 pr-10 lg:gap-14 lg:pr-14" @if ($isCopy) aria-hidden="true" @endif>
                        @foreach ($ticker as $index => $item)
                            {{-- Нечётные тезисы акцентом со звёздочкой,
                                 чётные — приглушённые (макет). --}}
                            <span
                                @class([
                                    'text-[11.5px] tracking-[0.04em] whitespace-nowrap lg:text-[12.5px]',
                                    'text-accent' => $index % 2 === 0,
                                    'text-ink-muted' => $index % 2 !== 0,
                                ])
                            >{{ $index % 2 === 0 ? '★ ' : '' }}{{ $item }}</span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@section('content')
    {{--
        Хиро.

        Секция подтягивается под шапку отрицательным отступом: в режиме
        `overlay` шапка остаётся в потоке (`relative z-50`), и без этого
        фотография начиналась бы под ней, а логотип читался бы на фоне
        страницы, а не на фото. Числа — собственная высота шапки: боковые
        отступы `py-3`/`lg:py-4` плюс самый высокий её элемент (кнопка
        ☎ и бургер 42px на мобильном, кнопка заявки 44px на десктопе).
        Если шапка изменит высоту, здесь появится полоса фона над фото или
        содержимое наедет под шапку — связь неочевидная, поэтому записана.

        Высота задана в `svh`, а не `vh`: на мобильных `vh` не учитывает
        сворачивание адресной строки, и первый экран прыгает при скролле.
    --}}
    <section class="relative -mt-[66px] flex h-[88svh] min-h-[560px] flex-col overflow-hidden lg:-mt-[76px] lg:min-h-[640px]">
        <div class="absolute inset-0">
            {{--
                LCP-элемент страницы, и обращаться с ним надо соответственно:
                `fetchpriority="high"`, никакого `loading` и два варианта
                по ширине. Рефлекс `loading="lazy"`, выработанный на карточках
                каталога, здесь откладывает загрузку главного изображения
                сайта — и ни один автотест этого не покажет.

                Изображение декоративное: смысл несёт текст поверх него,
                поэтому пустой `alt` и `aria-hidden`.
            --}}
            <img
                src="{{ Vite::asset('resources/images/hero-1920.webp') }}"
                srcset="{{ Vite::asset('resources/images/hero-960.webp') }} 960w, {{ Vite::asset('resources/images/hero-1920.webp') }} 1920w"
                sizes="100vw"
                fetchpriority="high"
                width="1920"
                height="1080"
                alt=""
                aria-hidden="true"
                class="size-full object-cover"
            >

            {{-- Маска: без неё белый текст на светлом участке фотографии
                 нечитаем. Значения стопов — из макета. --}}
            <div class="absolute inset-0 bg-gradient-to-b from-page/10 via-page/55 via-55% to-page/96"></div>
        </div>

        <div class="relative z-2 flex flex-1 items-end px-5 pt-[66px] pb-16 lg:px-8 lg:pt-[76px] lg:pb-22">
            <div class="mx-auto w-full max-w-page">
                <div class="mb-5 text-[13px] tracking-[0.22em] text-accent uppercase lg:mb-5.5">Импорт · Сервис · Экспертиза</div>

                <h1 class="mb-6 max-w-4xl font-display text-4xl leading-[1.14] font-semibold text-pretty lg:mb-6.5 lg:text-[56px]">
                    Автомобили из <span class="text-accent">Китая и Европы</span>. Под ключ.
                </h1>

                <p class="mb-10 max-w-xl text-lg leading-relaxed text-ink-muted">
                    Подбор, растаможка и доставка автомобиля любой сложности — и полный автосервис
                    для вашего парка. Один партнёр от заказа до обслуживания.
                </p>

                <div class="flex flex-wrap gap-4 lg:gap-5">
                    <a
                        href="{{ route('catalog.index') }}"
                        class="rounded-full bg-accent px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                    >Подобрать авто</a>

                    <a
                        href="#lead-form"
                        class="rounded-full border border-white/35 px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] transition hover:-translate-y-0.5 hover:border-white/70"
                    >Оставить заявку</a>
                </div>
            </div>
        </div>
    </section>

    {{--
        Промо-баннер. Управляется настройкой `home.promo` целиком: очищены
        и заголовок, и текст — секции нет вовсе. Пустая карточка с одним
        значком «%» читалась бы не как «мало контента», а как поломка.
    --}}
    @if ($promo !== null)
        <section class="px-5 py-14 lg:px-8">
            <div class="mx-auto max-w-page">
                <div class="relative flex flex-wrap items-center gap-6 overflow-hidden rounded-[20px] border border-white/10 bg-page-alt px-6 py-7 lg:px-9">
                    {{-- Фон необязателен и по умолчанию его нет (`image_id`
                         в сиде — `null`). Подложка обязательна вместе с ним:
                         текст поверх фотографии без затемнения нечитаем. --}}
                    @if ($promo['image_url'] !== null)
                        <img
                            src="{{ $promo['image_url'] }}"
                            alt=""
                            aria-hidden="true"
                            loading="lazy"
                            class="absolute inset-0 size-full object-cover"
                        >
                        <div class="absolute inset-0 bg-page/78"></div>
                    @endif

                    <div class="relative flex size-11 shrink-0 items-center justify-center rounded-full bg-accent font-display text-base font-bold text-page">%</div>

                    <div class="relative min-w-60 flex-1">
                        @if ($promo['title'] !== null)
                            <div class="mb-1 text-base font-semibold">{{ $promo['title'] }}</div>
                        @endif

                        @if ($promo['text'] !== null)
                            <div class="text-sm text-ink-muted">{{ $promo['text'] }}</div>
                        @endif
                    </div>

                    {{-- Кнопка не рендерится без подписи или адреса: кнопка
                         с пустым `href` уводит на текущую страницу и при этом
                         выглядит рабочей — та же ошибка, от которой подвал
                         защищён тестом вехи 4.1. Адрес выводится как есть,
                         экранированным: это и якорь `#lead-form` (умолчание
                         сида), и полный внешний адрес. --}}
                    @if ($promo['link_text'] !== null && $promo['link_url'] !== null)
                        <a
                            href="{{ $promo['link_url'] }}"
                            class="relative shrink-0 rounded-full border border-accent/50 px-6 py-3 text-sm font-semibold text-accent transition hover:-translate-y-px hover:bg-accent/12"
                        >{{ $promo['link_text'] }}</a>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{--
        Подборка авто с отметкой «показывать на главной».

        Пустая коллекция убирает секцию целиком — вместе с заголовком
        и ссылкой «Весь каталог →». Заголовок «Рекомендуемые автомобили»
        над пустой сеткой выглядит не как «мало контента», а как поломка.
        Фолбэк «показать свежие вместо отмеченных» отклонён: он прячет
        незаполненную настройку. Вместо него сервис пишет WARN.
    --}}
    @if ($cars->isNotEmpty())
        <section class="px-5 pt-16 pb-20 lg:px-8 lg:pb-30">
            <div class="mx-auto max-w-page">
                <div class="mb-10 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between lg:mb-14">
                    <div>
                        <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Каталог</div>

                        <h2 class="font-display text-3xl font-semibold lg:text-[34px]">
                            Рекомендуемые <span class="text-accent">автомобили</span>
                        </h2>
                    </div>

                    <a
                        href="{{ route('catalog.index') }}"
                        class="self-start border-b border-white/40 pb-1 text-[15px] transition-colors hover:border-accent hover:text-accent sm:self-auto"
                    >Весь каталог →</a>
                </div>

                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($cars as $car)
                        {{-- `heading="h3"` обязателен: у секции свой H2,
                             и карточка внутри неё должна быть уровнем ниже.
                             Забыть параметр — типовая ошибка переиспользования,
                             а иерархия заголовков не оформление: по ней ходят
                             скринридеры и по ней страницу разбирает поисковик. --}}
                        <x-car-card :car="$car" heading="h3" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Блок «Почему мы». Пустой список убирает секцию: пустая сетка под
         заголовком читается как поломка, а не как «мало контента». --}}
    @if ($advantages !== [])
        <section class="bg-page-alt px-5 py-20 lg:px-8 lg:py-30">
            <div class="mx-auto max-w-page">
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Почему мы</div>

                <h2 class="mb-12 max-w-160 font-display text-3xl font-semibold lg:mb-16 lg:text-[34px]">
                    Преимущества, за которые нас выбирают <span class="text-accent">клиенты</span>
                </h2>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($advantages as $advantage)
                        <div class="rounded-card border border-white/7 bg-surface p-7 transition duration-250 hover:-translate-y-1 hover:border-accent/30 lg:px-7 lg:py-9">
                            @if ($advantage['number'] !== null)
                                <div class="mb-5.5 flex size-10 items-center justify-center rounded-full bg-accent/14 font-display text-[15px] font-bold text-accent">
                                    {{ $advantage['number'] }}
                                </div>
                            @endif

                            <h3 class="mb-3 text-[17px] font-semibold">{{ $advantage['title'] }}</h3>

                            @if ($advantage['text'] !== null)
                                <p class="text-sm leading-relaxed text-ink-muted">{{ $advantage['text'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{--
        Два направления.

        Плашек две, а не три из макета: «Детейлинг» и «Доп. сервисы» —
        это блоки внутри страницы автосервиса, а не отдельные разделы
        (решение роадмапа). Без этого пояснения третью плашку однажды
        «вернут по макету».

        Тексты живут в шаблоне, а не в настройках: заводить под два абзаца
        ключи значило бы расширять веху 3.5 задним числом, а ссылки — это
        роуты, то есть код. Триггер завести настройку назван: первая
        просьба заказчика поправить эти тексты.
    --}}
    <section class="px-5 py-20 lg:px-8 lg:py-30">
        <div class="mx-auto max-w-page">
            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Направления</div>

            <h2 class="mb-12 max-w-160 font-display text-3xl font-semibold lg:mb-14 lg:text-[34px]">
                Не только продажа, но и <span class="text-accent">обслуживание</span>
            </h2>

            <div class="grid gap-8 lg:grid-cols-2">
                @foreach ([
                    ['letter' => 'А', 'title' => 'Автосервис', 'url' => route('services.index'), 'text' => 'ТО, диагностика и ремонт для всех марок, включая гибриды. Своя мастерская, шиномонтаж и детейлинг — весь цикл владения в одном месте.'],
                    ['letter' => 'З', 'title' => 'Запчасти', 'url' => route('parts.index'), 'text' => 'Подбираем оригинальные детали и проверенные аналоги по VIN. Цены и сроки уточняем после запроса — под конкретный автомобиль.'],
                ] as $direction)
                    <a
                        href="{{ $direction['url'] }}"
                        class="block rounded-card border border-white/10 px-8 py-11 transition duration-250 hover:-translate-y-1 hover:border-accent/50 hover:bg-surface"
                    >
                        <div class="mb-5 flex size-12 items-center justify-center rounded-xl bg-accent/14 font-display text-lg font-bold text-accent">
                            {{ $direction['letter'] }}
                        </div>

                        <h3 class="mb-4 font-display text-xl font-semibold">{{ $direction['title'] }}</h3>

                        <p class="mb-6 text-sm leading-relaxed text-ink-muted">{{ $direction['text'] }}</p>

                        <div class="text-sm text-accent">Подробнее →</div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{--
        Форма без источника: живая точка для сценария «общая форма»,
        в списке лидов такая заявка помечается «Общая форма».

        Двухколоночную раскладку включает `heading`; фон приходит готовым
        URL, а не путём — компонент не должен знать про `Vite::asset()`.
    --}}
    <x-lead-form
        submit="Отправить заявку"
        eyebrow="Заявка"
        heading="Оставьте заявку — подберём авто или запишем на сервис"
        text="Перезвоним в течение 15 минут в рабочее время. Все данные передаются менеджеру напрямую."
        :background="Vite::asset('resources/images/lead-bg.webp')"
    />
@endsection
