{{--
    Главная (вехи 4.2 и 4.6), переверстана по `assets/mockup-v2/index.html`.

    Данные собирает `App\Services\HomeContent`. Блоки, управляемые данными
    или настройками, при пустом значении не рендерятся вовсе — заголовок
    над пустой сеткой читается как поломка, а не как «мало контента».

    Порядок блоков: бегущая строка → хиро с фактами → полоса доверия →
    промо → подборка авто с фильтром статусов → быстрый подбор → этапы
    покупки → экосистема → основные услуги → прозрачность цены → отзывы →
    «Почему мы» → FAQ → заявка.

    Фоны чередуются `page ⇄ alt` — правило дизайн-системы запрещает больше
    двух оттенков на экране, а не смену тона. Полоса доверия, промо
    и подборка идут на `page` подряд, как в макете; `deep` остаётся редким
    акцентом и достаётся только этапам покупки. «Почему мы» переехал
    с позиции «после подборки» на «между отзывами и FAQ», а FAQ взят
    на `page` вместо `alt` из макета — иначе получилось бы два `alt` подряд.

    Что из макета сюда НЕ переносится и почему:

    - состав меню шапки («Продажа автомобилей · Запчасти · Сервис
      и детейлинг») и якорные адреса его пунктов. Навигация живёт
      в `App\Support\SiteMenu`, у нас `/catalog` и `/parts` — отдельные
      страницы, а не якоря главной. Макет v2 нарисован для одностраничника,
      и его структура просачивается по частям. Сторож — `LayoutTest`;
    - смешанный селект «Интересует» (автомобили вместе с услугами).
      Он потребовал бы составного значения `car:12` / `service:5` и разбора
      пары в `StoreLeadRequest`, то есть правки контракта вехи 3.7 и её
      тестов ради одного поля. Карточки автомобилей ведут на `/catalog/{car}`,
      где стоит форма с источником-автомобилем: точнее по данным и лучше
      для SEO, чем якорь на главной. Сторожа — `LeadStoreTest`
      и `SectionPagesTest`, обязанные проходить без единой правки.

    Правка любого из названных тестов «чтобы совпало с макетом» — сигнал,
    что веха вышла за свои границы.
--}}
@use(App\Enums\CarStatus)
@use(App\Enums\ServiceCategory)

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

        ФИКСИРОВАННОЙ высоты на мобильном при этом быть не должно — только
        минимальная. Блок фактов, добавленный вехой 4.6, делает содержимое
        первого экрана выше 88svh на узких экранах, и пара `height` +
        `overflow-hidden` обрезала бы два последних факта МОЛЧА: полосы
        прокрутки внутри секции не появится, ошибок в консоли не будет,
        а на десктопе, где верстают, всё видно целиком. Поэтому `h-[92svh]`
        задан только от `lg`, где содержимое встаёт в четыре колонки.
    --}}
    <section class="relative -mt-[66px] flex min-h-[88svh] flex-col overflow-hidden lg:-mt-[76px] lg:h-[92svh] lg:min-h-[680px]">
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

        <div class="relative z-2 flex flex-1 items-end px-5 pt-[66px] pb-14 lg:px-8 lg:pt-[76px] lg:pb-18">
            <div class="mx-auto w-full max-w-page">
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Автосалон · сервис · детейлинг в Москве</div>

                <h1 class="mb-6 max-w-4xl font-display text-[34px] leading-[1.14] font-semibold text-pretty sm:text-[44px] lg:mb-6.5 lg:text-[56px]">
                    Автомобиль из Китая — <span class="text-accent">под ключ</span>
                </h1>

                <p class="mb-9 max-w-[38rem] text-lg leading-[1.65] text-ink-muted">
                    Подбираем, проверяем, доставляем и готовим автомобиль к эксплуатации.
                    После выдачи остаёмся рядом: сервис, детейлинг и запчасти в одном месте.
                </p>

                <div class="mb-11 flex flex-wrap gap-4 lg:gap-5">
                    {{-- Главная кнопка ведёт в КАТАЛОГ, а не на якорь `#cars`,
                         как в макете. Расхождение осознанное: макет нарисован
                         для одностраничника, у которого отдельного каталога
                         нет вовсе, а здесь главная кнопка сайта не должна
                         приводить к шести карточкам вместо всей выдачи.
                         Без этой записи её однажды «поправят по макету». --}}
                    <a
                        href="{{ route('catalog.index') }}"
                        class="rounded-full bg-accent px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                    >Посмотреть автомобили</a>

                    <a
                        href="#selection"
                        class="rounded-full border border-white/35 px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] transition hover:-translate-y-0.5 hover:border-white/70"
                    >Подборка под бюджет</a>
                </div>

                {{-- Факты первого экрана. Тексты живут в шаблоне, а не
                     в настройках: это по одной строке на карточку, жёстко
                     привязанной к вёрстке блока, и репитер под них означал бы
                     форму настроек, в которой заказчик ломает первый экран.
                     Триггер завести настройку назван: первая просьба
                     заказчика поправить эти тексты.

                     Первые три пункта — статусы каталога словами покупателя,
                     а не значениями `CarStatus`: «В пути» здесь про ожидание,
                     а не про статус в базе (его в проекте нет вовсе). --}}
                <div class="grid grid-cols-2 gap-5 lg:grid-cols-4 lg:gap-7">
                    @foreach ([
                        ['title' => 'В наличии', 'text' => 'автомобили, которые можно посмотреть в салоне'],
                        ['title' => 'В пути', 'text' => 'понятный статус и ориентировочный срок'],
                        ['title' => 'Под заказ', 'text' => 'подбор комплектации и цвета под задачу'],
                        ['title' => 'После покупки', 'text' => 'сервис, детейлинг и расходники'],
                    ] as $fact)
                        <div class="border-t border-white/18 pt-3.5">
                            <div class="mb-1 text-sm font-semibold text-accent">{{ $fact['title'] }}</div>

                            <div class="text-[13px] leading-[1.5] text-ink-muted">{{ $fact['text'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{--
        Полоса доверия. Тексты — в шаблоне по той же причине, что и факты
        хиро: одна строка на карточку, привязанная к вёрстке блока.

        Секция на фоне `page`, и следующие за ней промо-баннер с подборкой —
        тоже: в макете полоса доверия и каталог стоят на одном фоне, а
        правило дизайн-системы запрещает не смену тона, а больше двух
        оттенков на экране.
    --}}
    <section class="px-5 py-14 lg:px-8 lg:py-18">
        <div class="mx-auto grid max-w-page gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['title' => 'Физический салон в Москве', 'text' => 'Можно увидеть автомобили и познакомиться с командой.'],
                ['title' => 'Прозрачный состав цены', 'text' => 'Показываем основные расходы до заключения сделки.'],
                ['title' => 'Фото- и видеоотчёт', 'text' => 'Фиксируем ключевые этапы проверки и доставки.'],
                ['title' => 'Собственная экосистема', 'text' => 'Продажа, сервис, детейлинг и запчасти.'],
            ] as $item)
                <div class="rounded-card border border-white/7 bg-surface px-6.5 py-6 transition duration-250 hover:-translate-y-1 hover:border-accent/30">
                    <div class="mb-2 text-[15px] font-semibold">{{ $item['title'] }}</div>

                    <div class="text-sm leading-[1.6] text-ink-muted">{{ $item['text'] }}</div>
                </div>
            @endforeach
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
        @php
            // Кнопки фильтра строятся из статусов, ФАКТИЧЕСКИ встреченных
            // в подборке, а не из полного enum-а. Кнопка «Под заказ»,
            // не дающая ни одной карточки, — это пустая выдача по клику,
            // то есть сломанное доверие к фильтру.
            //
            // Порядок берётся из порядка кейсов enum-а, а не из порядка
            // появления в подборке: иначе кнопки менялись бы местами
            // от одной перестановки карточек в админке к другой.
            //
            // Статуса «В пути» из макета в проекте нет вовсе — `CarStatus`
            // знает `InStock`, `OnOrder` и `Sold`, а проданные в подборку
            // не попадают (`HomeContent::cars()` пересекает `onHomepage()`
            // с `available()`).
            $statuses = array_values(array_filter(
                CarStatus::cases(),
                fn (CarStatus $status): bool => $cars->contains('status', $status),
            ));
        @endphp

        <section id="cars" class="px-5 pt-4 pb-20 lg:px-8 lg:pb-30">
            <div class="mx-auto max-w-page">
                <div class="mb-12 flex flex-col gap-5 lg:mb-14 lg:flex-row lg:items-end lg:justify-between lg:gap-12">
                    <div>
                        <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Продажа автомобилей</div>

                        <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                            Не бесконечный каталог, а понятные <span class="text-accent">предложения</span>
                        </h2>
                    </div>

                    <p class="leading-[1.7] text-ink-muted lg:max-w-110 lg:text-right">
                        Показываем статус автомобиля и ведём к следующему действию: посмотреть,
                        запросить точный расчёт или подобрать альтернативу.
                    </p>
                </div>

                {{--
                    Фильтр по статусу — единственный Alpine-компонент этой
                    секции. `x-data` инлайном, а не через `Alpine.data()`
                    из `@push('scripts')`: правило `RULES.md`.
                --}}
                <div x-data="{ status: 'all' }">
                    {{--
                        Панель фильтров скрыта до инициализации Alpine: без
                        скрипта кнопки не делают НИЧЕГО, а контрол, который
                        ничего не делает, обманывает (то же основание, по
                        которому вехой 4.3 скрыты стрелки фотогалереи).
                        Без JS панели нет, и видны все карточки подборки.

                        Это НЕ противоречит решению «фильтры каталога работают
                        без JavaScript»: там фильтр — GET-форма, и её выдачу
                        обязан видеть робот. Здесь — показ и скрытие шести
                        уже отрендеренных карточек, то есть чисто клиентское
                        удобство, и роботу скрывать нечего.

                        При единственном статусе панели нет вовсе:
                        фильтровать нечего, а две кнопки «Все» и «В наличии»
                        над одинаковой выдачей выглядят сломанными.
                    --}}
                    @if (count($statuses) > 1)
                        <div x-cloak class="mb-10 flex flex-wrap gap-2.5" role="group" aria-label="Фильтр по статусу">
                            {{-- Обработчики полной формой `x-on:click`,
                                 а не `@click`: `@` — префикс директив Blade
                                 (правило `ARCHITECTURE.md`).

                                 `aria-pressed` печатается строкой, а не
                                 булевым: `aria-pressed` — не булев атрибут
                                 HTML, и его отсутствие означает не «не
                                 нажата», а «не переключатель». Заливку
                                 активной кнопки несёт сам атрибут
                                 (`aria-pressed:` — вариант Tailwind),
                                 поэтому состояние и оформление не могут
                                 разойтись. --}}
                            <button
                                type="button"
                                aria-pressed="true"
                                x-on:click="status = 'all'"
                                x-bind:aria-pressed="status === 'all' ? 'true' : 'false'"
                                class="rounded-full border border-white/15 px-5 py-2.5 text-sm transition-colors hover:border-accent/50 hover:text-accent aria-pressed:border-accent aria-pressed:bg-accent aria-pressed:font-semibold aria-pressed:text-page"
                            >Все</button>

                            @foreach ($statuses as $status)
                                <button
                                    type="button"
                                    aria-pressed="false"
                                    x-on:click="status = '{{ $status->value }}'"
                                    x-bind:aria-pressed="status === '{{ $status->value }}' ? 'true' : 'false'"
                                    class="rounded-full border border-white/15 px-5 py-2.5 text-sm transition-colors hover:border-accent/50 hover:text-accent aria-pressed:border-accent aria-pressed:bg-accent aria-pressed:font-semibold aria-pressed:text-page"
                                >{{ $status->label() }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($cars as $car)
                            {{-- Директива висит на ОБЁРТКЕ, а не на самой
                                 карточке: `x-car-card` общий с каталогом
                                 и блоком похожих, и `x-show` внутри него
                                 уехал бы на три страницы сразу — там,
                                 где никакого фильтра нет.

                                 `heading="h3"` обязателен: у секции свой H2,
                                 и карточка внутри неё должна быть уровнем
                                 ниже. Иерархия заголовков не оформление:
                                 по ней ходят скринридеры и по ней страницу
                                 разбирает поисковик. --}}
                            <div x-show="status === 'all' || status === '{{ $car->status->value }}'">
                                <x-car-card :car="$car" heading="h3" />
                            </div>
                        @endforeach

                        {{-- Карточка-приглашение седьмой ячейкой сетки.
                             Под фильтр она НЕ попадает намеренно: это
                             не автомобиль, а выход из ситуации «нужного
                             нет», и именно суженная фильтром выдача —
                             момент, когда предложение подобрать нужнее
                             всего. --}}
                        <div class="flex flex-col justify-center gap-5 rounded-card border border-dashed border-white/16 p-8">
                            <h3 class="font-display text-[19px] font-semibold">Не нашли модель?</h3>

                            <p class="text-sm leading-[1.65] text-ink-muted">
                                Подберём варианты по бюджету, типу двигателя и сроку покупки —
                                без необходимости разбираться во всём рынке самостоятельно.
                            </p>

                            {{-- `self-start` обязателен: в колоночном flex-
                                 контейнере элементы растягиваются по ширине
                                 по умолчанию, и кнопка стала бы во всю
                                 карточку. Заголовок и абзац при этом должны
                                 остаться полной ширины, поэтому `items-start`
                                 на контейнере здесь не подходит. --}}
                            <a
                                href="#selection"
                                class="self-start rounded-full bg-accent px-6 py-3 text-sm font-semibold text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                            >Получить подборку</a>
                        </div>
                    </div>
                </div>

                <div class="mt-11 flex justify-center">
                    <a
                        href="{{ route('catalog.index') }}"
                        class="rounded-full border border-white/35 px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] transition hover:-translate-y-0.5 hover:border-white/70"
                    >Весь каталог →</a>
                </div>
            </div>
        </section>
    @endif

    {{--
        Быстрый подбор.

        Секция рендерится только при известных границах цены: обе `null`
        означают пустой или бесценовой каталог, и слайдер «от null до null»
        был бы контролом без данных (правило проекта о блоках, управляемых
        данными).

        Счёт идёт по РЕАЛЬНОМУ каталогу, а не по формуле макета
        (`js/mockup.js` считает «направления» выражением
        `value < 3000000 ? 2 : …`). Цифра на живом сайте, не подкреплённая
        данными, — это обещание, которого нет.
    --}}
    @if ($selector['price_min'] !== null && $selector['price_max'] !== null)
        @php
            // Шаг ползунка — решение вёрстки, поэтому живёт в шаблоне.
            $step = 100000;

            // Границы округляются ДО кратных шагу, и это не косметика.
            // Допустимые значения `input[type=range]` — это `min + n * step`,
            // и при некратном `max` верхнее значение недостижимо: ползунок
            // упирается в предпоследнюю ступень. Состояние «бюджет на
            // максимуме», от которого зависит, уходит ли `price_to` в адрес
            // каталога, стало бы недостижимым — молча, потому что визуально
            // ползунок всё равно доезжает до правого края.
            $sliderMin = (int) (floor($selector['price_min'] / $step) * $step);
            $sliderMax = (int) (ceil($selector['price_max'] / $step) * $step);

            // Один шаг хода гарантирован: при единственной цене в каталоге
            // границы совпали бы и ползунок оказался бы мёртвым контролом.
            $sliderMax = max($sliderMax, $sliderMin + $step);
        @endphp

        <section id="selection" class="bg-page-alt px-5 py-20 lg:px-8 lg:py-30">
            <div class="mx-auto max-w-page">
                <div class="mb-12 flex flex-col gap-5 lg:mb-14 lg:flex-row lg:items-end lg:justify-between lg:gap-12">
                    <div>
                        <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Быстрый подбор</div>

                        <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                            Автомобиль под ваш бюджет и <span class="text-accent">сценарий</span>
                        </h2>
                    </div>

                    <p class="leading-[1.7] text-ink-muted lg:max-w-110 lg:text-right">
                        Короткая форма вместо длинного каталога: видно, сколько автомобилей
                        подходит под запрос, ещё до заявки.
                    </p>
                </div>

                {{--
                    Данные уезжают в `x-data` через `@js(...)`, а не
                    конкатенацией строк: директива кодирует значение
                    с `JSON_HEX_*`, то есть кавычки и угловые скобки
                    не могут разорвать атрибут.

                    Фильтрация повторяет правила `CatalogFilter` — иначе
                    счётчик обещал бы одно, а каталог по той же ссылке
                    показывал другое. Автомобиль без цены при заданном
                    бюджете НЕ считается: `CatalogFilter` применяет
                    `price_to` как `where('price', '<=', …)`, и записи
                    с `price = null` из выдачи выпадают. Счётчик, который
                    их включает, обещает больше, чем покажет каталог,
                    и расхождение выглядело бы как баг фильтра.
                --}}
                <div
                    class="grid gap-6 lg:grid-cols-[1.35fr_1fr] lg:gap-8"
                    x-data="{
                        cars: @js($selector['cars']),
                        budget: {{ $sliderMax }},
                        sliderMax: {{ $sliderMax }},
                        engine: '',
                        term: '',

                        get capped() {
                            return this.budget < this.sliderMax;
                        },

                        get matches() {
                            return this.cars.filter((car) => {
                                if (this.engine !== '' && car.engine !== this.engine) {
                                    return false;
                                }

                                if (this.term !== '' && car.status !== this.term) {
                                    return false;
                                }

                                if (this.capped && (car.price === null || car.price > this.budget)) {
                                    return false;
                                }

                                return true;
                            }).length;
                        },

                        get budgetLabel() {
                            return new Intl.NumberFormat('ru-RU').format(this.budget) + ' ₽';
                        },

                        get href() {
                            const params = new URLSearchParams();

                            if (this.capped) {
                                params.set('price_to', this.budget);
                            }

                            if (this.engine !== '') {
                                params.set('engine', this.engine);
                            }

                            if (this.term !== '') {
                                params.set('status', this.term);
                            }

                            const query = params.toString();

                            return '{{ route('catalog.index') }}' + (query === '' ? '' : '?' + query);
                        },
                    }"
                >
                    {{--
                        Карточка формы целиком скрыта до инициализации
                        Alpine: ползунок и чипы без скрипта не делают
                        ничего. Без JS от блока остаётся правая карточка —
                        серверное число доступных автомобилей и кнопка
                        в каталог, то есть «В каталоге N автомобилей →
                        Перейти в каталог». Честно и полезно; со скриптом
                        становится подбором.
                    --}}
                    <div x-cloak class="rounded-card border border-white/8 bg-surface p-7 lg:p-9">
                        <div>
                            <div class="mb-3.5 flex items-baseline justify-between gap-4">
                                <label for="selection-budget" class="text-sm font-medium text-ink-muted">Бюджет</label>

                                <output
                                    for="selection-budget"
                                    class="font-display text-lg font-semibold text-accent"
                                    x-text="budgetLabel"
                                >{{ number_format($sliderMax, 0, ',', ' ') }} ₽</output>
                            </div>

                            {{-- `x-model` здесь уместен, в отличие от селекта
                                 услуги: значение контрола НЕ задаёт сервер
                                 через `old()`, а начальное состояние
                                 компонента совпадает с атрибутом `value`.
                                 Затирать серверный выбор нечем.

                                 Нативный range на тёмной теме браузер красит
                                 сам, поэтому дорожка и бегунок заданы явно
                                 произвольными значениями. --}}
                            <input
                                type="range"
                                id="selection-budget"
                                min="{{ $sliderMin }}"
                                max="{{ $sliderMax }}"
                                step="{{ $step }}"
                                value="{{ $sliderMax }}"
                                x-model.number="budget"
                                class="h-1 w-full appearance-none rounded-full bg-white/15 [&::-moz-range-thumb]:size-5.5 [&::-moz-range-thumb]:cursor-pointer [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-accent [&::-moz-range-thumb]:shadow-[0_0_0_6px_rgb(249_194_39/0.18)] [&::-webkit-slider-thumb]:size-5.5 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:cursor-pointer [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-accent [&::-webkit-slider-thumb]:shadow-[0_0_0_6px_rgb(249_194_39/0.18)]"
                            >
                        </div>

                        {{-- Чипы: `input` визуально скрыт, состояние несёт
                             соседний `span` через `peer-checked`. Именно
                             `input`, а не кнопка: группа радиокнопок
                             доступна с клавиатуры и объявляется скринридером
                             как выбор из набора без единой строки ARIA. --}}
                        @foreach ([
                            [
                                'legend' => 'Тип двигателя',
                                'model' => 'engine',
                                'name' => 'selection-engine',
                                // Варианты, реально встречающиеся в каталоге,
                                // плюс «Не важно» первым: чип, дающий ноль
                                // автомобилей, ломает доверие к подбору.
                                'options' => [['value' => '', 'label' => 'Не важно'], ...$selector['engines']],
                            ],
                            [
                                'legend' => 'Срок покупки',
                                'model' => 'term',
                                'name' => 'selection-term',
                                // Значения — статусы каталога, а не выдуманные
                                // сроки: кнопка ведёт в каталог с параметром
                                // `status`, и подпись обязана соответствовать
                                // тому, что человек там увидит.
                                'options' => [
                                    ['value' => CarStatus::InStock->value, 'label' => 'Сейчас'],
                                    ['value' => CarStatus::OnOrder->value, 'label' => '1–2 месяца'],
                                    ['value' => '', 'label' => 'Изучаю рынок'],
                                ],
                            ],
                        ] as $field)
                            <fieldset class="mt-7">
                                <legend class="mb-3.5 text-sm font-medium text-ink-muted">{{ $field['legend'] }}</legend>

                                <div class="flex flex-wrap gap-2.5">
                                    @foreach ($field['options'] as $option)
                                        <label class="cursor-pointer">
                                            <input
                                                type="radio"
                                                name="{{ $field['name'] }}"
                                                value="{{ $option['value'] }}"
                                                x-model="{{ $field['model'] }}"
                                                class="peer sr-only"
                                            >

                                            <span class="inline-block rounded-full border border-white/15 px-5 py-2.5 text-sm transition-colors hover:border-accent/50 peer-checked:border-accent peer-checked:bg-accent peer-checked:font-semibold peer-checked:text-page peer-focus-visible:outline-2 peer-focus-visible:outline-offset-[3px] peer-focus-visible:outline-accent">{{ $option['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    </div>

                    <div class="flex flex-col justify-between gap-7 rounded-card border border-white/8 bg-surface p-7 lg:p-9">
                        <div>
                            {{-- Счётчик печатается поверх серверного числа,
                                 а не вместо него: без скрипта здесь остаётся
                                 количество доступных автомобилей каталога. --}}
                            <div
                                class="font-display text-[64px] leading-none font-bold text-accent lg:text-[76px]"
                                x-text="matches"
                            >{{ $selector['total'] }}</div>

                            {{-- Подпись намеренно не согласуется с числом:
                                 «автомобиля» верно для 2 и неверно для 1 и 5,
                                 а склонять число на клиенте и на сервере
                                 значит завести два места, где живёт одно
                                 правило русской грамматики, — и разойтись
                                 в них. Фраза без существительного в счётной
                                 форме верна при любом значении. --}}
                            <div class="mt-3 mb-3.5 text-lg font-semibold">в подборке под ваш запрос</div>

                            <p class="text-sm leading-[1.65] text-ink-muted">
                                Менеджер уточнит приоритеты и подготовит персональную подборку
                                с расчётом по каждому варианту.
                            </p>
                        </div>

                        {{-- Серверный `href` ведёт в каталог без параметров,
                             Alpine навешивает поверх адрес с фильтрами.
                             Имена параметров — из контракта вехи 3.6
                             (`price_to`, `engine`, `status`), менять их
                             нельзя: каталог разбирает ровно эти. --}}
                        <a
                            href="{{ route('catalog.index') }}"
                            x-bind:href="href"
                            class="rounded-full bg-accent px-9 py-4.5 text-center text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                        >Получить подборку</a>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{--
        Этапы покупки. Единственный блок страницы на фоне `deep` — по правилу
        дизайн-системы это редкий акцент, а не третий рабочий оттенок.
        Стоит между двумя `alt`-секциями намеренно: без него быстрый подбор
        и экосистема слились бы в один длинный экран одного тона.

        Пустой `home.steps` убирает секцию целиком, вместе с шапкой блока.
    --}}
    @if ($steps !== [])
        <section id="process" class="bg-deep px-5 py-20 lg:px-8 lg:py-30">
            <div class="mx-auto max-w-page">
                <div class="mb-12 flex flex-col gap-5 lg:mb-14 lg:flex-row lg:items-end lg:justify-between lg:gap-12">
                    <div>
                        <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Как проходит покупка</div>

                        <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                            Каждый этап понятен <span class="text-accent">до начала сделки</span>
                        </h2>
                    </div>

                    <p class="leading-[1.7] text-ink-muted lg:max-w-110 lg:text-right">
                        Что делает ЛАО КАРС, что требуется от клиента и какой статус
                        он видит на каждом шаге.
                    </p>
                </div>

                {{-- Карточки на фоне `page`, а не `surface`: секция уже
                     на `deep`, и `surface` дал бы третий оттенок на экране. --}}
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                    @foreach ($steps as $step)
                        <article class="rounded-card border border-white/8 bg-page p-7 transition duration-250 hover:-translate-y-1 hover:border-accent/30">
                            @if ($step['number'] !== null)
                                <div class="mb-5.5 flex size-10 items-center justify-center rounded-full bg-accent/14 font-display text-[15px] font-bold text-accent">
                                    {{ $step['number'] }}
                                </div>
                            @endif

                            <h3 class="mb-3 text-[17px] font-semibold">{{ $step['title'] }}</h3>

                            @if ($step['text'] !== null)
                                <p class="text-sm leading-[1.65] text-ink-muted">{{ $step['text'] }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{--
        Экосистема после покупки — на месте блока «Два направления» вехи 4.2.

        Плашек четыре, а не две, и это НЕ отмена прежнего решения. Веха 4.2
        заменила три плашки макета v1 двумя, потому что «Детейлинг»
        и «Доп. сервисы» — блоки внутри страницы автосервиса, а не отдельные
        разделы. Здесь плашки ведут на ЯКОРЯ этих блоков (`/services#detailing`),
        а не заводят разделы: якоря настоящие, их выдаёт
        `ServiceCategory::anchor()` вехи 4.4. Плашка ведёт к блоку —
        противоречия нет.

        Запчасти, в отличие от макета, ведут на собственную страницу `/parts`,
        а не в заявку: у нас она есть, а у макета-одностраничника не было.

        Тексты живут в шаблоне, а не в настройках: заводить под четыре абзаца
        ключи значило бы расширять веху 3.5 задним числом, а адреса — это
        роуты, то есть код. Триггер завести настройку назван: первая просьба
        заказчика поправить эти тексты.
    --}}
    <section id="service" class="bg-page-alt px-5 py-20 lg:px-8 lg:py-30">
        <div class="mx-auto grid max-w-page items-start gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:gap-14">
            <div class="relative flex min-h-105 items-end overflow-hidden rounded-card lg:min-h-140">
                {{--
                    Тот же кадр, что на `/services`: второй копии
                    не заводим — одна фотография мастерской на сайт.

                    Изображение декоративное, смысл несёт текст поверх него,
                    поэтому пустой `alt` и `aria-hidden`. В отличие от хиро —
                    `loading="lazy"`: блок стоит глубоко ниже первого экрана,
                    и правило LCP из `RULES.md` к нему не относится.
                --}}
                <img
                    src="{{ Vite::asset('resources/images/service-panel.webp') }}"
                    width="720"
                    height="961"
                    loading="lazy"
                    alt=""
                    aria-hidden="true"
                    class="absolute inset-0 size-full object-cover"
                >

                {{-- Маска: подпись лежит на самом светлом участке кадра,
                     поэтому нижний стоп почти непрозрачный — приглушённый
                     текст поверх блика нечитаем. --}}
                <div class="absolute inset-0 bg-gradient-to-t from-page/96 via-page/62 to-page/10"></div>

                <div class="relative p-7">
                    <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">После покупки</div>

                    <h3 class="mb-3 font-display text-[22px] leading-[1.25] font-semibold">
                        Мы не исчезаем после передачи ключей
                    </h3>

                    <p class="text-sm leading-[1.65] text-ink-muted">
                        Собственный сервис превращает разовую покупку в долгосрочные отношения.
                    </p>
                </div>
            </div>

            <div>
                <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Экосистема ЛАО КАРС</div>

                <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                    Всё для автомобиля <span class="text-accent">в одном месте</span>
                </h2>

                {{-- Плашки — ссылки, а не карточки-иллюстрации: каждое
                     направление обязано вести к следующему действию.

                     `block` обязателен: инлайновый `<a>` игнорирует
                     вертикальные отступы и ломает сетку. --}}
                <div class="mt-9 grid gap-5 sm:grid-cols-2">
                    @foreach ([
                        ['letter' => 'А', 'title' => 'Автосервис', 'url' => route('services.index').'#'.ServiceCategory::Maintenance->anchor(), 'text' => 'Диагностика, регламентные работы и подготовка автомобиля к эксплуатации.'],
                        ['letter' => 'Д', 'title' => 'Детейлинг', 'url' => route('services.index').'#'.ServiceCategory::Detailing->anchor(), 'text' => 'Защитные покрытия, полировка, уход за салоном и кузовом.'],
                        ['letter' => 'З', 'title' => 'Запчасти', 'url' => route('parts.index'), 'text' => 'Оригинальные детали и проверенные аналоги по VIN с понятным каналом заказа.'],
                        ['letter' => 'П', 'title' => 'Поддержка', 'url' => '#lead-form', 'text' => 'Единая точка контакта по автомобилю после покупки.'],
                    ] as $card)
                        <a
                            href="{{ $card['url'] }}"
                            class="block rounded-card border border-white/7 bg-surface p-6.5 transition duration-250 hover:-translate-y-1 hover:border-accent/30"
                        >
                            <div class="mb-5 flex size-12 items-center justify-center rounded-xl bg-accent/14 font-display text-lg font-bold text-accent">
                                {{ $card['letter'] }}
                            </div>

                            <h3 class="mb-2.5 text-[17px] font-semibold">{{ $card['title'] }}</h3>

                            <p class="text-sm leading-[1.65] text-ink-muted">{{ $card['text'] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{--
        ОБЩАЯ ОБЁРТКА ALPINE открывается здесь и закрывается ПОСЛЕ секции
        заявки. Между прайсом и формой лежат четыре блока (прозрачность цены,
        отзывы, «Почему мы», FAQ), и это ровно тот случай, ради которого
        правило записано: область `x-data` — поддерево DOM, а не страница.
        Обработчик на строке прайса за пределами обёртки не сработал бы
        ВОВСЕ, а симптом читается как «Alpine не работает» и ищется
        в скрипте, а не в разметке. Две соседние обёртки — одна на прайс,
        другая на форму — та же ошибка под другим соусом.

        Компонент делает ровно одно: подставляет выбранную позицию в селект
        формы. `x-data` инлайном, а не через `Alpine.data()` из
        `@push('scripts')` — правило `RULES.md`.
    --}}
    <div
        x-data="{
            pick(id) {
                const select = document.getElementById('lead-service');

                if (select !== null) {
                    select.value = id;
                }
            },
        }"
    >
        {{--
            Основные услуги. Витрина усечена до трёх позиций на категорию
            (`home.services_per_category`): полный прайс из восемнадцати
            строк вытеснил бы с главной всё, ради чего человек на неё пришёл.
            Селект формы усечению НЕ подлежит и остаётся надмножеством
            витрины — иначе клик по видимой строке подставлял бы
            несуществующую опцию, и Alpine молча ничего не сделал бы.

            Пустой `serviceGroups` убирает секцию целиком. WARN про пустой
            прайс пишет `HomeContent`.
        --}}
        @if ($serviceGroups !== [])
            <section id="services" class="px-5 py-20 lg:px-8 lg:py-30">
                <div class="mx-auto max-w-page">
                    <div class="mb-12 flex flex-col gap-5 lg:mb-14 lg:flex-row lg:items-end lg:justify-between lg:gap-12">
                        <div>
                            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Услуги</div>

                            <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                                Основные услуги <span class="text-accent">сервиса и детейлинга</span>
                            </h2>
                        </div>

                        <p class="leading-[1.7] text-ink-muted lg:max-w-110 lg:text-right">
                            Свой сервис, а не партнёрская сеть: работы, шиномонтаж, детейлинг
                            и подготовка автомобиля выполняются в одной мастерской.
                        </p>
                    </div>

                    <div class="grid gap-6 min-[900px]:grid-cols-2">
                        @foreach ($serviceGroups as $group)
                            <article class="rounded-card border border-white/8 bg-surface p-7 lg:p-8">
                                <div class="mb-2 flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent/14 font-display text-[15px] font-bold text-accent">
                                        {{ $group['badge'] }}
                                    </div>

                                    <h3 class="text-[17px] font-semibold">{{ $group['category']->label() }}</h3>
                                </div>

                                {{-- Пустое описание убирает абзац, но не
                                     карточку: прайс важнее текста. --}}
                                @if ($group['note'] !== null)
                                    <p class="mb-4.5 text-[13px] leading-[1.6] text-ink-muted">{{ $group['note'] }}</p>
                                @endif

                                <div class="flex flex-col">
                                    @foreach ($group['items'] as $service)
                                        {{-- Строка — обычная ссылка на форму,
                                             а не кнопка: без скрипта клик
                                             доводит до формы, и посетитель
                                             выбирает позицию сам. Alpine лишь
                                             подставляет её в селект, поэтому
                                             `x-on:click` идёт БЕЗ `.prevent` —
                                             переход к форме нужен и с ним.

                                             Полная форма `x-on:click`, а не
                                             `@click`: `@` — префикс директив
                                             Blade (правило `ARCHITECTURE.md`). --}}
                                        <a
                                            href="#lead-form"
                                            x-on:click="pick({{ $service->getKey() }})"
                                            class="group flex items-baseline justify-between gap-4 border-t border-white/8 py-3.5 first:border-t-0"
                                        >
                                            <span class="text-[15px] leading-[1.4] transition-colors group-hover:text-accent">{{ $service->title }}</span>

                                            {{-- «По запросу» — не цена,
                                                 и набирать её акцентом наравне
                                                 с суммой значит обещать то,
                                                 чего в строке нет. Развилка
                                                 идёт по `hasPrice()`, а не
                                                 по сравнению вывода со строкой
                                                 «по запросу»: иначе
                                                 формулировка жила бы
                                                 в двух местах. --}}
                                            <span
                                                @class([
                                                    'shrink-0 whitespace-nowrap text-sm',
                                                    'font-display font-semibold text-accent' => $service->hasPrice(),
                                                    'font-medium text-ink-muted' => ! $service->hasPrice(),
                                                ])
                                            >{{ $service->priceLabel() }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-11 flex justify-center">
                        <a
                            href="{{ route('services.index') }}"
                            class="rounded-full bg-accent px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                        >Посмотреть все услуги</a>
                    </div>
                </div>
            </section>
        @endif

        {{--
            Прозрачность цены. Блок показывает СТРУКТУРУ расходов, а не суммы:
            цена зависит от автомобиля и даты, и вписанная сюда устареет молча.

            Пустой `home.price_breakdown` убирает секцию целиком, вместе
            с картой-нотой: нота без состава цены ничего не сообщает — она
            объясняет, что будет с тем, чего на странице нет.
        --}}
        @if ($priceBreakdown !== [])
            <section class="bg-page-alt px-5 py-20 lg:px-8 lg:py-30">
                <div class="mx-auto max-w-page">
                    <div class="mb-12 flex flex-col gap-5 lg:mb-14 lg:flex-row lg:items-end lg:justify-between lg:gap-12">
                        <div>
                            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Прозрачность цены</div>

                            <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                                Клиент видит, из чего складывается <span class="text-accent">стоимость</span>
                            </h2>
                        </div>

                        <p class="leading-[1.7] text-ink-muted lg:max-w-110 lg:text-right">
                            Точные суммы рассчитываются под конкретный автомобиль и дату —
                            здесь только состав расходов.
                        </p>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-[1.3fr_1fr] lg:gap-8">
                        <div class="rounded-card border border-white/8 bg-surface px-7 py-3 lg:px-9 lg:py-4">
                            @foreach ($priceBreakdown as $row)
                                {{-- Граница снизу у каждой строки и погашена
                                     у последней: иначе список заканчивается
                                     линией, повторяющей край карточки. --}}
                                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-white/8 py-5 last:border-b-0">
                                    <div class="text-[15px] font-semibold">{{ $row['title'] }}</div>

                                    @if ($row['note'] !== null)
                                        <div class="text-sm text-ink-muted">{{ $row['note'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- `items-start` обязателен: в колоночном flex-
                             контейнере плашка растянулась бы на всю ширину
                             карточки вопреки `inline-block`, и оговорка стала
                             бы похожа на заголовок блока. --}}
                        <div class="flex flex-col items-start gap-4.5 rounded-card border border-white/8 bg-surface p-7 lg:p-9">
                            {{-- Та же разметка, что у оговорки цен
                                 на `/services`: смысл один — «это не оферта», —
                                 и две по-разному набранные плашки читались бы
                                 как две разные по силе оговорки. --}}
                            <span class="inline-block shrink-0 rounded-full border border-accent/40 px-2.5 py-1 text-[11px] tracking-[0.08em] text-accent uppercase">
                                Не публичная оферта
                            </span>

                            <h3 class="font-display text-[22px] leading-[1.3] font-semibold">
                                <span class="text-accent">Точный расчёт</span><br>по конкретной комплектации
                            </h3>

                            {{-- Текст живёт в шаблоне: он описывает, что
                                 произойдёт после нажатия кнопки, и меняется
                                 только вместе с ней. --}}
                            <p class="text-sm leading-[1.65] text-ink-muted">
                                Кнопка ведёт в короткую заявку. Менеджер отправляет понятный
                                документ с составом расходов и датой актуальности.
                            </p>

                            <a
                                href="#lead-form"
                                class="rounded-full bg-accent px-9 py-4.5 text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                            >Получить расчёт</a>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{--
            Отзывы — первый потребитель модели `Review` и её модерации
            (веха 3.2). Показываются только опубликованные; путь к этому
            закрыт скоупом в сервисе, а не условием здесь.

            Карточки-шаблоны макета с подписями «Имя клиента · город · дата»
            сюда НЕ переносятся: это указание заказчику, чем наполнять блок,
            а не контент. Пустая коллекция убирает секцию целиком — заголовок
            «Отзывы» над пустой сеткой читается как поломка.
        --}}
        @if ($reviews->isNotEmpty())
            <section id="about" class="px-5 py-20 lg:px-8 lg:py-30">
                <div class="mx-auto max-w-page">
                    <div class="mb-12 flex flex-col gap-5 lg:mb-14 lg:flex-row lg:items-end lg:justify-between lg:gap-12">
                        <div>
                            <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Доверие</div>

                            <h2 class="font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                                Отзывы доказывают результат, а не <span class="text-accent">просто хвалят</span>
                            </h2>
                        </div>

                        <p class="leading-[1.7] text-ink-muted lg:max-w-110 lg:text-right">
                            Публикуем после проверки: каждый отзыв проходит модерацию
                            в админке перед тем, как попасть на сайт.
                        </p>
                    </div>

                    <div class="grid gap-6 md:grid-cols-3">
                        @foreach ($reviews as $review)
                            <article class="flex flex-col gap-4 rounded-card border border-white/8 bg-surface p-7">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        {{-- Фото необязательно: `media_id`
                                             объявлен `nullOnDelete()`, и
                                             удалённое изображение оставляет
                                             отзыв без фото, а не роняет
                                             страницу. Снимок декоративный —
                                             имя автора стоит рядом текстом. --}}
                                        @if ($review->photo_url !== null)
                                            <img
                                                src="{{ $review->photo_url }}"
                                                width="44"
                                                height="44"
                                                loading="lazy"
                                                alt=""
                                                aria-hidden="true"
                                                class="size-11 shrink-0 rounded-full bg-photo object-cover"
                                            >
                                        @endif

                                        <div class="min-w-0">
                                            <div class="text-[15px] font-semibold">{{ $review->author_name }}</div>

                                            @if ($review->author_context !== null)
                                                <div class="text-[13px] text-ink-faint">{{ $review->author_context }}</div>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Звёзды рисуются символами, а не
                                         изображениями, — но ряд символов
                                         скринридер прочитает как мусор
                                         («звезда звезда звезда…»), поэтому
                                         оценка объявлена целиком:
                                         `role="img"` плюс `aria-label`
                                         заменяют содержимое одной фразой. --}}
                                    @if ($review->rating !== null)
                                        <div
                                            role="img"
                                            aria-label="Оценка: {{ $review->rating }} из 5"
                                            class="shrink-0 text-sm tracking-[0.08em] text-accent"
                                        >{{ str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating) }}</div>
                                    @endif
                                </div>

                                <p class="text-[15px] leading-[1.7]">{{ $review->body }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- Блок «Почему мы». Вехой 4.6 переехал сюда, между отзывами и FAQ:
             на прежнем месте (сразу после подборки) он оказался бы третьим
             `alt`-блоком подряд. Разметка карточек не менялась — она общая
             по формату с блоком «почему сюда» на `/services`, и две разные
             карточки на одинаковых данных разъехались бы на первой же правке
             одной из них.

             Пустой список убирает секцию: пустая сетка под заголовком
             читается как поломка, а не как «мало контента». --}}
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
            FAQ.

            Нативные `<details>` / `<summary>`, а не Alpine: `<details>` —
            готовый аккордеон браузера, он работает без скрипта, доступен
            с клавиатуры и объявляется скринридером сам. Alpine здесь был бы
            заменой работающего механизма на свой.

            Микроразметки `FAQPage` тут НЕТ намеренно: Google перестал
            показывать её у коммерческих сайтов, а разметка, которая ничего
            не даёт в выдаче, — это код без потребителя. Триггер вернуть
            назван: FAQ снова начинает попадать в сниппеты коммерческих
            запросов — тогда она собирается тем же приёмом, что
            `CarStructuredData`.

            Пустой `home.faq` убирает секцию целиком. Элемент без ответа
            в неё не попадает вовсе — его отбрасывает `HomeContent`.
        --}}
        @if ($faq !== [])
            <section class="px-5 py-20 lg:px-8 lg:py-30">
                <div class="mx-auto max-w-page">
                    <div class="mb-12 lg:mb-14">
                        <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">FAQ</div>

                        <h2 class="max-w-160 font-display text-3xl leading-[1.15] font-semibold text-pretty lg:text-[34px]">
                            Закрываем вопросы <span class="text-accent">до звонка менеджеру</span>
                        </h2>
                    </div>

                    <div class="border-t border-white/10">
                        @foreach ($faq as $index => $item)
                            {{-- Первый вопрос раскрыт: закрытый целиком блок
                                 читается как список ссылок, а не как ответы,
                                 и человек уходит, не поняв, что здесь есть
                                 текст. --}}
                            <details class="group border-b border-white/10" @if ($index === 0) open @endif>
                                {{-- Маркер браузера гасится обоими способами:
                                     `list-none` не убирает треугольник
                                     в WebKit — там за него отвечает
                                     собственный псевдоэлемент. Вместо маркера
                                     акцентный «+», превращающийся в «–»
                                     на раскрытом элементе. --}}
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-5 py-5.5 text-base font-semibold transition-colors hover:text-accent [&::-webkit-details-marker]:hidden after:shrink-0 after:font-display after:text-xl after:leading-none after:text-accent after:content-['+'] group-open:after:content-['–'] lg:text-[17px]">
                                    {{ $item['question'] }}
                                </summary>

                                <p class="max-w-240 pb-6 text-[15px] leading-[1.7] text-ink-muted">{{ $item['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{--
            Секция заявки. `:services` включает селект «Интересует» — тот же
            четвёртый сценарий формы, что на `/services` (веха 4.4): скрытое
            `source_type=service` плюс селект `source_id`. Именно он делает
            работающей подстановку по клику из прайса выше.

            Двухколоночную раскладку включает `heading`; фон приходит готовым
            URL, а не путём — компонент не должен знать про `Vite::asset()`.
        --}}
        @php
            // Контакт с пустым значением не рендерится вовсе: подпись
            // «Телефон» над пустотой хуже отсутствующего блока — то же
            // правило, что защищает подвал тестом вехи 4.1.
            //
            // Проверка строгая (`!== null`), а не `array_filter()` без
            // колбэка: правило `RULES.md` — нестрогая проверка выбрасывает
            // и значения-нули, а сервис уже привёл пустые строки к `null`.
            $leadContacts = array_values(array_filter([
                ['label' => 'Телефон', 'value' => $contacts['phone']],
                ['label' => 'Почта', 'value' => $contacts['email']],
                ['label' => 'Адрес', 'value' => $contacts['address']],
            ], fn (array $contact): bool => $contact['value'] !== null));
        @endphp

        <x-lead-section
            :services="$services"
            submit="Отправить заявку"
            eyebrow="Заявка"
            heading="Получите подборку автомобилей под ваш запрос"
            text="Перезвоним в течение 15 минут в рабочее время. Все данные передаются менеджеру напрямую."
            :background="Vite::asset('resources/images/lead-bg.webp')"
        >
            @if ($leadContacts !== [])
                <x-slot:below>
                    {{-- Значения печатаются текстом, а не ссылками `tel:`
                         и `mailto:`, — намеренно. Нормализация телефона
                         в адрес живёт в `SiteHeader` и `SiteFooter`, и третья
                         её копия здесь разъехалась бы с ними; тапнуть номер
                         на мобильном при этом есть где — подвал стоит прямо
                         под этой секцией. Триггер пересмотра: понадобится
                         четвёртое место — тогда нормализация переезжает
                         в `app/Support/`, а не копируется ещё раз. --}}
                    <div class="flex flex-wrap gap-7">
                        @foreach ($leadContacts as $contact)
                            <div>
                                <div class="mb-1.5 text-xs tracking-[0.1em] text-ink-muted uppercase">{{ $contact['label'] }}</div>

                                <div class="font-display text-base font-semibold text-accent">{{ $contact['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </x-slot:below>
            @endif
        </x-lead-section>
    </div>
@endsection
