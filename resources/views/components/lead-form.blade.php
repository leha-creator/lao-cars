{{--
    Форма заявки — один компонент на все формы сайта (веха 3.7),
    свёрстанный по UI Kit макета §05 и секции заявки главной
    (`Home.dc.html`, строки 199–216) вехой 4.1.

    Решения вехи 3.7 в этом шаблоне — инварианты, а не оформление, и
    переживают переверстку только за счёт объясняющих комментариев.
    Каждое из них выглядит как мусор из заглушки для того, кто открыл
    файл, чтобы навести красоту, — поэтому они помечены явно.

    Скрытого поля `page_url` здесь нет намеренно. Адрес страницы определяет
    сервер (`StoreLeadRequest::pageUrl()`): значение уходит ссылкой
    в Telegram менеджеру, и клиентское поле превратило бы уведомление
    в вектор фишинга — менеджер видит «Страница: …» и кликает.

    Якорь `#lead-form` — цель кнопки «Оставить заявку» в шапке и умолчание
    `home.promo.link_url`. Он остаётся на `<section>` в обеих раскладках.

    Раскладок две (веха 4.2), и ветвление идёт ВОКРУГ формы: центрированная
    карточка на страницах разделов (`heading` не задан — нынешнее поведение)
    и две колонки на фоновом фото на главной. Всё, что внутри `<form>`,
    ветвление не затрагивает — там инварианты вехи 3.7, и правка теста
    `LeadStoreTest` или `SectionPagesTest` под новую вёрстку означала бы,
    что сломано поведение, а не разметка.
--}}
@php
    // Двухколоночная раскладка включается наличием заголовка секции:
    // без него выводить пустую левую колонку не из чего.
    $twoColumn = $heading !== null;
@endphp

<section
    id="lead-form"
    @class([
        'px-5 py-16 lg:px-8 lg:py-24',
        'relative overflow-hidden bg-page-alt lg:py-30' => $twoColumn,
    ])
>
    @if ($background !== null)
        {{-- В отличие от хиро — `loading="lazy"`: этот блок стоит внизу
             страницы и в первый экран не попадает. Изображение декоративное,
             смысл несёт текст поверх него. --}}
        <img
            src="{{ $background }}"
            alt=""
            aria-hidden="true"
            loading="lazy"
            class="absolute inset-0 size-full object-cover"
        >

        {{-- Подложка обязательна: без неё текст поверх фотографии нечитаем.
             Значения стопов — из макета. --}}
        <div class="absolute inset-0 bg-gradient-to-r from-page-alt/62 via-page-alt/42 via-45% to-page-alt/28"></div>
    @endif

    <div @class([
        'relative mx-auto max-w-page',
        'grid items-start gap-12 lg:grid-cols-2 lg:gap-20' => $twoColumn,
    ])>
        @if ($twoColumn)
            <div>
                @if ($eyebrow !== null)
                    <div class="mb-4 text-[13px] tracking-[0.2em] text-accent uppercase">{{ $eyebrow }}</div>
                @endif

                {{-- Тень под текстом — из макета: фотография под ним светлая
                     местами, и одной градиентной подложки не хватает. --}}
                <h2 class="mb-6 max-w-120 font-display text-3xl font-semibold [text-shadow:0_2px_24px_rgb(0_0_0/0.85)] lg:text-[34px]">
                    {{ $heading }}
                </h2>

                @if ($text !== null)
                    <p class="max-w-110 text-[15px] leading-[1.7] text-ink [text-shadow:0_1px_16px_rgb(0_0_0/0.8)]">
                        {{ $text }}
                    </p>
                @endif
            </div>
        @endif

        <div @class([
            'rounded-[20px] border border-white/8 p-6 lg:p-9',
            'mx-auto max-w-2xl bg-page-alt' => ! $twoColumn,
            'bg-page/50' => $twoColumn,
        ])>
            {{-- Заголовок карточки при заданном `heading` не выводится:
                 иначе это второй H2 подряд про одно и то же. --}}
            @unless ($twoColumn)
                <h2 class="mb-6 font-display text-2xl font-semibold lg:text-[28px]">{{ $title }}</h2>
            @endunless

            @if (session('status'))
                {{-- Одно и то же сообщение видят и человек, и бот, заполнивший
                     ловушку: ответ, отличающийся от успеха, выдал бы ловушку. --}}
                <p class="mb-6 rounded-field border border-accent/40 bg-accent/10 px-4 py-3 text-sm text-accent">
                    {{ session('status') }}
                </p>
            @endif

            <form method="POST" action="{{ route('leads.store') }}" class="flex flex-col gap-4">
                @csrf

                @if ($source !== null)
                    {{-- Алиасы morph map (`car` / `service`) — ровно то, что лежит
                         в `leads.source_type`. Значение сервер перепроверяет:
                         подделать скрытое поле — вопрос одной правки в devtools. --}}
                    <input type="hidden" name="source_type" value="{{ $sourceType() }}">
                    <input type="hidden" name="source_id" value="{{ $source->getKey() }}">
                @endif

                {{-- Ловушка для ботов. Уводится за пределы экрана, а не прячется
                     через `class="hidden"` или `type="hidden"`: `display:none` боты
                     распознают, а hidden-поля пропускают. `tabindex="-1"`
                     и `autocomplete="off"` — чтобы в неё не заехал живой человек
                     с клавиатуры или автозаполнением. --}}
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label>
                        Сайт
                        <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-medium text-ink-muted">Имя</span>
                    {{-- `old()` во всех полях без исключения: при ошибке валидации
                         клиент не должен вводить телефон и комментарий заново. --}}
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        required
                        class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                    >
                    @error('name')
                        <span class="text-sm text-danger">{{ $message }}</span>
                    @enderror
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-medium text-ink-muted">Телефон</span>
                    <input
                        type="tel"
                        name="phone"
                        value="{{ old('phone') }}"
                        required
                        class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                    >
                    @error('phone')
                        <span class="text-sm text-danger">{{ $message }}</span>
                    @enderror
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-medium text-ink-muted">E-mail</span>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                    >
                    @error('email')
                        <span class="text-sm text-danger">{{ $message }}</span>
                    @enderror
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink-muted">Удобный способ связи</span>
                        {{-- Нативный `select` на тёмном фоне читается благодаря
                             `color-scheme: dark` в базовых стилях: без него
                             браузер красит выпадающий список по светлой схеме. --}}
                        <select
                            name="contact_method"
                            class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                        >
                            <option value="">Не важно</option>
                            @foreach (App\Enums\ContactMethod::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('contact_method') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('contact_method')
                            <span class="text-sm text-danger">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink-muted">Удобное время звонка</span>
                        <select
                            name="preferred_time"
                            class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                        >
                            <option value="">Не важно</option>
                            @foreach (App\Enums\PreferredTime::options() as $value => $label)
                                <option value="{{ $value }}" @selected(old('preferred_time') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('preferred_time')
                            <span class="text-sm text-danger">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                @if ($parts)
                    {{-- Поля подбора запчасти: по ним заявка отличается от прочих
                         (`Lead::isPartsRequest()`), а не источником. --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-sm font-medium text-ink-muted">Марка автомобиля</span>
                            <input
                                type="text"
                                name="part_brand"
                                value="{{ old('part_brand') }}"
                                class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                            >
                            @error('part_brand')
                                <span class="text-sm text-danger">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-sm font-medium text-ink-muted">Модель</span>
                            <input
                                type="text"
                                name="part_model"
                                value="{{ old('part_model') }}"
                                class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                            >
                            @error('part_model')
                                <span class="text-sm text-danger">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink-muted">VIN</span>
                        {{-- 17 символов — не только стандарт VIN, но и длина
                             колонки: правило валидации мягче колонки означало бы
                             ошибку драйвера PostgreSQL вместо сообщения на форме. --}}
                        <input
                            type="text"
                            name="part_vin"
                            value="{{ old('part_vin') }}"
                            maxlength="17"
                            class="rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                        >
                        @error('part_vin')
                            <span class="text-sm text-danger">{{ $message }}</span>
                        @enderror
                    </label>
                @endif

                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-medium text-ink-muted">Комментарий</span>
                    <textarea
                        name="message"
                        rows="4"
                        class="resize-y rounded-field border border-white/15 bg-page px-4.5 py-4 text-[15px] transition-colors focus:border-accent/70 focus:outline-none"
                    >{{ old('message') }}</textarea>
                    @error('message')
                        <span class="text-sm text-danger">{{ $message }}</span>
                    @enderror
                </label>

                @error('source_id')
                    <span class="text-sm text-danger">{{ $message }}</span>
                @enderror

                <button
                    type="submit"
                    class="mt-1.5 rounded-full bg-accent py-4.5 text-[15px] font-semibold tracking-[0.02em] text-page transition hover:-translate-y-0.5 hover:bg-accent-hover"
                >{{ $submit }}</button>
            </form>
        </div>
    </div>
</section>
