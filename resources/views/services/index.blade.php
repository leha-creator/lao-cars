{{--
    Автосервис (веха 4.4), свёрстан по `assets/mockup-v2/services.html`.

    Данные собирает `App\Services\ServicesPageContent`: блоки прайса
    по категориям, описания категорий, оговорка о ценах и блок «почему сюда».
    Блоки, управляемые данными, при пустом значении не рендерятся вовсе —
    заголовок «Шиномонтаж» над пустым местом читается как поломка, а не
    как «мало контента».

    Три вещи из макета сюда НЕ переносятся, и каждая — не мелочь:

    - состав меню шапки («Продажа автомобилей · Запчасти · Сервис
      и детейлинг»). Навигация живёт в `App\Support\SiteMenu`, у нас
      `/catalog` и `/parts` — отдельные страницы, а не якоря главной.
      Макет v2 нарисован для одностраничника, и его структура просачивается
      по частям: сначала подпись пункта, потом ссылка. Сторож — `LayoutTest`;
    - заголовок «Сервис и детейлинг». H1 приходит из настройки
      `services_page.intro_title` (веха 3.5), и заменить её константой
      из макета значит отобрать у заказчика поле, которое ему уже отдали.
      Сторож — `SectionPagesTest`;
    - акцентное слово в H1 (`<em>детейлинг</em>`). Настройка хранит текст,
      а не разметку: значение печатается экранированным (иначе это XSS
      через админку), а редактор не поймёт, зачем в поле угловые скобки.
      H1 этой страницы одноцветный — единственное осознанное расхождение
      с макетом.

    Правка любого из двух названных тестов «чтобы совпало с макетом» —
    сигнал, что веха вышла за свои границы.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('app.name'))
@section('description', 'ТО и ремонт, шиномонтаж, детейлинг и дополнительные сервисы для автомобилей любых марок.')

@section('content')
    <x-page-heading eyebrow="Услуги" :title="$title" :intro="$intro">
        {{-- Якорная навигация: прайс длинный, и человек, пришедший
             за шиномонтажом, не должен пролистывать три чужие категории.

             Ссылки строятся из того же `$blocks`, что и сами блоки, а не
             из полного списка категорий: ссылка на несуществующий якорь
             не работает МОЛЧА. При одном блоке навигации нет вовсе —
             листать нечего. --}}
        @if (count($blocks) > 1)
            <x-slot:below>
                <nav class="flex flex-wrap gap-2.5" aria-label="Категории услуг">
                    @foreach ($blocks as $block)
                        <a
                            href="#{{ $block['anchor'] }}"
                            class="rounded-full border border-white/15 px-5 py-2.5 text-sm transition-colors hover:border-accent/50 hover:text-accent"
                        >{{ $block['category']->label() }}</a>
                    @endforeach
                </nav>
            </x-slot:below>
        @endif
    </x-page-heading>

    @if ($blocks !== [])
        <div class="px-5 lg:px-8">
            <div class="mx-auto max-w-page">
                @foreach ($blocks as $index => $block)
                    {{-- Верхняя граница разделяет блоки, поэтому у первого
                         её нет: иначе она удвоила бы границу шапки страницы. --}}
                    <section
                        id="{{ $block['anchor'] }}"
                        @class([
                            'py-13 lg:py-16',
                            'border-t border-white/10' => $index > 0,
                        ])
                    >
                        <div class="grid items-start gap-8 lg:grid-cols-[0.8fr_1.2fr] lg:gap-14">
                            <div>
                                <div class="mb-5 flex size-10 items-center justify-center rounded-xl bg-accent/14 font-display text-[15px] font-bold text-accent">
                                    {{ $block['badge'] }}
                                </div>

                                <h2 class="font-display text-[26px] leading-[1.2] font-semibold lg:text-3xl">
                                    {{ $block['category']->label() }}
                                </h2>

                                {{-- Пустое описание убирает абзац, но не блок:
                                     прайс важнее текста, и категория без
                                     описания остаётся на странице. --}}
                                @if ($block['note'] !== null)
                                    <p class="mt-3.5 text-sm leading-[1.7] text-ink-muted">{{ $block['note'] }}</p>
                                @endif
                            </div>

                            {{-- Прайс в две колонки: позиции короткие,
                                 и в одну колонку страница превращается в узкую
                                 ленту с огромным пустым правым полем. --}}
                            <div class="grid md:grid-cols-2 md:gap-x-10">
                                @foreach ($block['items'] as $service)
                                    {{-- Строка — обычная ссылка на форму. Без
                                         скрипта клик доводит до формы,
                                         и посетитель выбирает позицию сам
                                         (подстановку добавляет обёртка
                                         с `x-data` ниже).

                                         Верхняя граница гасится у первой строки
                                         КАЖДОЙ колонки, а не только у первой
                                         строки сетки. Разметкой это не решается:
                                         число позиций в категории меняется
                                         в админке. --}}
                                    <a
                                        href="#lead-form"
                                        class="group flex items-baseline justify-between gap-4 border-t border-white/8 py-3.5 first:border-t-0 md:[&:nth-child(2)]:border-t-0"
                                    >
                                        <span class="text-[15px] leading-[1.4] transition-colors group-hover:text-accent">{{ $service->title }}</span>

                                        {{-- «По запросу» — не цена, и набирать
                                             её акцентом наравне с суммой значит
                                             обещать то, чего в строке нет.
                                             Развилка идёт по `hasPrice()`,
                                             а не по сравнению вывода со строкой
                                             «по запросу»: иначе формулировка
                                             жила бы в двух местах. --}}
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
                        </div>
                    </section>
                @endforeach

                {{-- Оговорка о ценах. Текст юридически значимый и живёт
                     в настройках; пустое значение убирает блок ЦЕЛИКОМ —
                     вместе с плашкой, потому что плашка без пояснения
                     ничего не сообщает. --}}
                @if ($disclaimer !== null)
                    <div class="mb-6 flex flex-wrap items-center gap-4 rounded-card border border-accent/25 bg-accent/6 px-6.5 py-5.5">
                        <span class="inline-block shrink-0 rounded-full border border-accent/40 px-2.5 py-1 text-[11px] tracking-[0.08em] text-accent uppercase">
                            Не публичная оферта
                        </span>

                        <p class="min-w-64 flex-1 text-sm leading-[1.65] text-ink-muted">{{ $disclaimer }}</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{--
        «Почему сюда» — репитер `services_page.advantages`. Пустой список
        убирает секцию ЦЕЛИКОМ, вместе с фотопанелью: заголовок над пустой
        сеткой читается как поломка, а фотография без карточек рядом —
        как обрезанный блок.
    --}}
    @if ($advantages !== [])
        <section class="bg-page-alt px-5 py-20 lg:px-8 lg:py-30">
            <div class="mx-auto grid max-w-page items-start gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:gap-14">
                <div class="relative flex min-h-105 items-end overflow-hidden rounded-card lg:min-h-140">
                    {{--
                        Изображение декоративное: смысл несёт текст поверх
                        него, поэтому пустой `alt` и `aria-hidden`. В отличие
                        от хиро — `loading="lazy"`: блок стоит ниже первого
                        экрана, и правило LCP из `RULES.md` к нему
                        не относится.

                        Фотография стоковая, из макета, и подлежит замене
                        заказчиком — строка про это стоит во «внешних
                        зависимостях» роадмапа рядом с `hero-*.webp`
                        и `lead-bg.webp`. Снаружи страница выглядит готовой,
                        и заметить чужой кадр больше некому.
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
                         текст поверх блика нечитаем. Значения стопов
                         из макета. --}}
                    <div class="absolute inset-0 bg-gradient-to-t from-page/96 via-page/62 to-page/10"></div>

                    {{-- Подпись живёт в шаблоне, а не в настройках: она
                         описывает конкретный кадр и меняется только вместе
                         с ним. Настройка под неё означала бы, что заказчик
                         может подписать фотографию, которую не может
                         заменить. --}}
                    <div class="relative p-7">
                        <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Мастерская</div>

                        <h3 class="mb-3 font-display text-[22px] leading-[1.25] font-semibold">
                            Тот же сервис, что готовит наши автомобили к выдаче
                        </h3>

                        <p class="text-sm leading-[1.65] text-ink-muted">
                            Обслуживаться в ЛАО КАРС можно независимо от того, где куплен автомобиль.
                        </p>
                    </div>
                </div>

                <div>
                    <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">Почему сюда</div>

                    <h2 class="font-display text-3xl font-semibold lg:text-[34px]">
                        Сервис, который <span class="text-accent">не заканчивается на выдаче</span>
                    </h2>

                    {{-- Разметка карточки повторяет блок «Почему мы» главной:
                         формат значения у настроек один и тот же, и две
                         разные карточки на одинаковых данных разъехались бы
                         на первой же правке одной из них. --}}
                    <div class="mt-9 grid gap-5 sm:grid-cols-2">
                        @foreach ($advantages as $advantage)
                            <div class="rounded-card border border-white/7 bg-surface p-7 transition duration-250 hover:-translate-y-1 hover:border-accent/30">
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
            </div>
        </section>
    @endif

    <x-lead-section title="Записаться на сервис" submit="Записаться" />
@endsection
