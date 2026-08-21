{{--
    Контакты (веха 4.5) — вторая половина вехи, первой была «О компании».

    Данные готовит `App\Services\ContactsPageContent`: группы настроек
    `contacts.*`, `contacts_page.*` и `socials.*`. Blade в базу не ходит.

    **Макета у страницы нет ни в v1, ни в v2** — она единственная такая
    из шести. Собрана по UI Kit и по рецепту внутренней страницы из
    `docs/design-system.md`: `x-page-heading` вместо фотографического хиро,
    карточки на `bg-surface` с обводкой `border-line`, чередование `page`
    и `page-alt` сверху вниз, `x-lead-section` последней секцией. Ровно
    те же кирпичи, из которых собраны `/services`, `/parts` и `/about`, —
    и это не бедность выбора, а причина, по которой страница без макета
    не выглядит чужой среди остальных.

    Страница светлая целиком (веха 4.11). `theme-light` вешается на КАЖДУЮ
    секцию и на сам `x-page-heading`, а не на обёртку вокруг них: обёртка
    получила бы боковые поля страницы и оставила бы по краям тёмные полосы.
    Секцию заявки внизу красит сам компонент по своей раскладке.

    Блок без данных не рендерится вовсе — правило проекта. Здесь это
    касается каждого блока по отдельности: очищенный адрес убирает и
    карточку адреса, и карту; расписание без рабочих дней убирает часы;
    незаполненная соцсеть не выводится, но остальные остаются.

    **Карта — iframe виджета Яндекс.Карт, а не карта на API-ключе.**
    Разбор решения (и почему `sandbox` на нём не стоит) — в PHPDoc
    `App\Support\MapEmbed`, там же список разрешённых хостов.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('app.name'))
@section('description', 'Адрес, телефон, почта и мессенджеры ЛАО КАРС. Часы работы, карта проезда и форма обратной связи.')

@section('content')
    {{-- Микроразметка организации (веха 4.14). Печатается через `@json`
         с `JSON_HEX_TAG` по правилу проекта: сюда попадают адрес, телефон
         и почта, введённые администратором, то есть данные из базы в HTML
         без экранирования Blade. Обоснование флагов разобрано подробно
         в `catalog/show.blade.php`, где стоит тот же тег.

         Тег на странице обязан остаться ЕДИНСТВЕННЫМ: два скрипта с разными
         версиями одних и тех же данных — классический способ получить
         в выдаче не то, что ожидалось. Сторож — `ContactsStructuredDataTest`,
         и он проверяет именно количество тегов, а не подстроки. --}}
    <script type="application/ld+json">@json($structuredData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

    <x-page-heading eyebrow="Контакты" :title="$title" :intro="$intro" class="theme-light" />

    {{-- Способы связи. Карточка на `bg-surface`, а не на `page-alt`:
         в светлой ветке `page-alt` ТЕМНЕЕ фона страницы, то есть карточка
         стала бы утопленной вместо приподнятой. Разбор — в комментарии
         `x-lead-section`, где на этом уже обожглись. --}}
    <section class="theme-light px-5 pt-12 pb-16 lg:px-8 lg:pb-20">
        <div class="mx-auto max-w-page">
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @if ($address !== null)
                    <div class="rounded-card border border-line bg-surface p-6">
                        <div class="mb-4 flex size-11 items-center justify-center rounded-full bg-accent-solid/14 text-accent">
                            {{-- Размер значка задаётся ЗДЕСЬ обязательно:
                                 умолчания у компонента нет намеренно —
                                 класс с места вызова не заменяет собственный
                                 класс компонента, а дописывается к нему. --}}
                            <x-ui.icon name="pin" class="size-5.5" />
                        </div>

                        <div class="mb-2 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Адрес</div>
                        <div class="text-[15px] leading-relaxed">{{ $address }}</div>
                    </div>
                @endif

                @if ($phone !== null)
                    <div class="rounded-card border border-line bg-surface p-6">
                        <div class="mb-4 flex size-11 items-center justify-center rounded-full bg-accent-solid/14 text-accent">
                            <x-ui.icon name="phone" class="size-5.5" />
                        </div>

                        <div class="mb-2 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Телефон</div>

                        {{-- Ссылка, а не текст. До этой вехи номер на
                             канонической странице контактов не нажимался,
                             хотя в шапке и подвале был ссылкой, — а с
                             телефона нажатие здесь и есть главный сценарий
                             страницы. Адрес готовит `App\Support\PhoneLink`;
                             пустой после чистки номер даёт `null`, и тогда
                             остаётся подпись без ссылки, а не `href="tel:"`,
                             который выглядит рабочим и ведёт в никуда. --}}
                        @if ($phoneHref !== null)
                            <a href="tel:{{ $phoneHref }}" class="text-[15px] transition-colors hover:text-accent">{{ $phone }}</a>
                        @else
                            <div class="text-[15px]">{{ $phone }}</div>
                        @endif
                    </div>
                @endif

                @if ($email !== null)
                    <div class="rounded-card border border-line bg-surface p-6">
                        <div class="mb-4 flex size-11 items-center justify-center rounded-full bg-accent-solid/14 text-accent">
                            <x-ui.icon name="mail" class="size-5.5" />
                        </div>

                        <div class="mb-2 text-[13px] tracking-[0.1em] text-ink-muted uppercase">E-mail</div>
                        <a href="mailto:{{ $email }}" class="text-[15px] break-all transition-colors hover:text-accent">{{ $email }}</a>
                    </div>
                @endif
            </div>

            {{-- Мессенджеры остаются текстовыми таблетками. Логотипов
                 Telegram, WhatsApp и VK в наборе `x-ui.icon` нет намеренно:
                 это чужие торговые знаки со своими гайдлайнами, и
                 нарисованный «контуром в толщине 1.6» логотип — это
                 не логотип, а похожая фигура. Подписи держит словарём
                 `App\Support\SocialLinks`, он же отсеивает незаполненные. --}}
            @if ($socials !== [])
                <div class="mt-10">
                    <div class="mb-4 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Мессенджеры и соцсети</div>

                    <div class="flex flex-wrap gap-3">
                        @foreach ($socials as $social)
                            <a
                                href="{{ $social['url'] }}"
                                target="_blank"
                                rel="noopener"
                                class="rounded-full border border-line-strong px-5 py-2.5 text-sm transition hover:border-accent/50 hover:text-accent"
                            >{{ $social['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- Часы работы. Расписание без единого рабочего дня даёт
         `scheduleSummary === null` и убирает секцию целиком: таблица из
         семи «выходных» читается как поломка данных, а не как «часы
         не заданы». WARN про это пишет сам `WorkSchedule`.

         Внутри секции — две формы одного и того же, и выбирает между ними
         НЕ шаблон, а `WorkSchedule::rows()`:

         - разные дни («Пн–Пт 9:00–19:00, Сб 10:00–16:00, Вс выходной») —
           семь строк. Страница контактов каноничная для организации, на ней
           стоит `LocalBusiness`, и построчный вид здесь читается лучше
           склейки, которой хватает подвалу;
         - одинаковая неделя — одна строка «Без выходных, 9:00–21:00». Семь
           повторов «9:00–21:00» подряд не несут ни бита сверх неё, а именно
           так компания и работает сегодня.

         Обе формы собирает один класс из одного значения настройки, то есть
         разойтись с подвалом они не могут. --}}
    @if ($scheduleSummary !== null)
        <section class="theme-light bg-page-alt px-5 py-16 lg:px-8 lg:py-20">
            <div class="mx-auto max-w-page">
                <div class="mb-6 flex items-center gap-3">
                    <span class="flex size-11 items-center justify-center rounded-full bg-accent-solid/14 text-accent">
                        <x-ui.icon name="clock" class="size-5.5" />
                    </span>

                    <h2 class="font-display text-2xl font-semibold lg:text-[28px]">Часы работы</h2>
                </div>

                @if ($scheduleDays !== [])
                    <div class="max-w-xl rounded-card border border-line bg-surface p-2">
                        @foreach ($scheduleDays as $row)
                            <div @class([
                                'flex items-baseline justify-between gap-6 rounded-[10px] px-4 py-3 text-[15px]',
                                // Выходной приглушён, а не вычеркнут: строка
                                // всё ещё несёт день недели, и её надо читать.
                                'text-ink-muted' => $row['hours'] === null,
                            ])>
                                <span>{{ $row['label'] }}</span>
                                <span @class(['font-medium' => $row['hours'] !== null])>{{ $row['hours'] ?? 'Выходной' }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="inline-block rounded-card border border-line bg-surface px-6 py-4 text-[17px] font-medium">
                        {{ $scheduleSummary }}
                    </div>
                @endif

                @if ($scheduleNote !== null)
                    <p class="mt-4 max-w-xl text-[14px] text-ink-muted">{{ $scheduleNote }}</p>
                @endif
            </div>
        </section>
    @endif

    {{-- Карта. `mapUrl` уже проверен `MapEmbed`: это либо адрес из настройки,
         прошедший список хостов, либо виджет, собранный по адресу компании.
         `null` означает, что нет ни того, ни другого, — и тогда секции нет
         вовсе, а не пустая рамка на её месте.

         Ссылка «Открыть в Яндекс.Картах» стоит рядом не для красоты: iframe
         может не загрузиться (блокировщик, корпоративная сеть, офлайн),
         и без ссылки блок в этом случае оказывается пустым прямоугольником
         на месте адреса. --}}
    @if ($mapUrl !== null)
        <section class="theme-light px-5 py-16 lg:px-8 lg:py-20">
            <div class="mx-auto max-w-page">
                <h2 class="mb-8 font-display text-2xl font-semibold lg:text-[28px]">Как нас найти</h2>

                <div class="grid gap-8 lg:grid-cols-[1.6fr_1fr] lg:gap-10">
                    {{-- Соотношение сторон фиксировано, чтобы страница
                         не прыгала, пока виджет грузится. `loading="lazy"`
                         — блок ниже первого экрана; `title` обязателен,
                         иначе скринридер читает «фрейм». `sandbox` не стоит
                         намеренно: разбор — в `App\Support\MapEmbed`. --}}
                    <div class="aspect-4/3 overflow-hidden rounded-card border border-line sm:aspect-16/9">
                        <iframe
                            src="{{ $mapUrl }}"
                            title="Карта проезда"
                            loading="lazy"
                            allowfullscreen
                            class="size-full border-0"
                        ></iframe>
                    </div>

                    <div>
                        @if ($routeText !== null)
                            <div class="mb-4 flex size-11 items-center justify-center rounded-full bg-accent-solid/14 text-accent">
                                <x-ui.icon name="route" class="size-5.5" />
                            </div>

                            <div class="mb-2 text-[13px] tracking-[0.1em] text-ink-muted uppercase">Как добраться</div>
                            <p class="text-[15px] leading-[1.8] text-ink-muted">{{ $routeText }}</p>
                        @endif

                        @if ($mapExternalUrl !== null)
                            <a
                                href="{{ $mapExternalUrl }}"
                                target="_blank"
                                rel="noopener"
                                @class([
                                    'inline-flex items-center gap-2 rounded-full border border-line-strong px-5 py-2.5 text-sm transition hover:border-accent/50 hover:text-accent',
                                    'mt-6' => $routeText !== null,
                                ])
                            >
                                <x-ui.icon name="pin" class="size-4.5" />
                                Открыть в Яндекс.Картах
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    <x-lead-section title="Написать нам" submit="Отправить" />
@endsection
