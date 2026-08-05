{{--
    Форма заявки — один компонент на все формы сайта (веха 3.7),
    свёрстанный по UI Kit макета §05 вехой 4.1.

    Раскладки здесь больше нет: секцию с фоном и колонками даёт
    `x-lead-section` (веха 4.3), а этот компонент — только `<form>`,
    заголовок над ней и сообщение об успехе. Граница проходит по `<form>`
    ровно потому, что внутри неё — инварианты вехи 3.7, а снаружи —
    оформление, которое меняется от страницы к странице.

    Обёртки-контейнера у компонента нет намеренно: карточку с фоном,
    рамкой и отступами рисует вызывающая сторона (`x-lead-section` или
    правая колонка карточки автомобиля). Иначе форма несла бы свой фон
    внутрь чужой карточки — подложка на подложке.

    Решения вехи 3.7 в этом шаблоне — инварианты, а не оформление, и
    переживают переверстку только за счёт объясняющих комментариев.
    Каждое из них выглядит как мусор из заглушки для того, кто открыл
    файл, чтобы навести красоту, — поэтому они помечены явно.

    Скрытого поля `page_url` здесь нет намеренно. Адрес страницы определяет
    сервер (`StoreLeadRequest::pageUrl()`): значение уходит ссылкой
    в Telegram менеджеру, и клиентское поле превратило бы уведомление
    в вектор фишинга — менеджер видит «Страница: …» и кликает.

    Якоря `#lead-form` здесь тоже нет: он живёт на обёртке. На него ведут
    кнопка заявки в шапке и умолчание `home.promo.link_url`, то есть он
    обязан быть на странице ровно один, — а форма на карточке автомобиля
    и секция заявки могут встретиться на одной странице.
--}}
@if ($title !== null)
    <h2 class="mb-6 font-display text-2xl font-semibold lg:text-[28px]">{{ $title }}</h2>
@endif

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
