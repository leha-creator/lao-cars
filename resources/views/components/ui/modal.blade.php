{{--
    Подтверждение принятой заявки (веха 4.7).

    Окно ОДНО на страницу и живёт в layout, а не рядом с формой. Причина
    та же, по которой якорь `#lead-form` живёт на обёртке: форма встречается
    на странице дважды (карточка автомобиля плюс секция заявки), а окно
    подтверждения обязано быть одно — два наложенных друг на друга диалога
    с одинаковым текстом читаются как поломка.

    Связь с формой идёт СОБЫТИЕМ: форма делает `$dispatch('lead-accepted')`,
    окно слушает `x-on:lead-accepted.window`.

    Это намеренный обход правила «обработчик за пределами `x-data`-обёртки
    не срабатывает», а не его нарушение. Правило описывает область
    компонента — поддерево DOM, — и модификатор `.window` как раз
    и есть штатный способ эту границу пересечь: событие Alpine создаётся
    с `bubbles: true` и доходит до `window`. Записано это здесь потому,
    что первый же, кто вспомнит правило вехи 4.6, попробует «починить»
    связь общей обёрткой вокруг половины страницы — а обернуть layout
    целиком означало бы одну обёртку на все компоненты сайта.

    Окно скрыто `x-cloak` по тому же основанию, по которому вехой 4.3
    скрыты стрелки фотогалереи: без скрипта оно не откроется никогда,
    и мелькнувший при загрузке диалог — это не «мало стилей», а поломка
    в глазах посетителя.

    Окно ТЁМНОЕ на любой странице (веха 4.11), и это решение, а не пропуск.
    Оно живёт в layout, то есть вне `.theme-light`, и получает тёмные токены
    вместе с шапкой и подвалом. Формы стоят и на светлых страницах,
    и на тёмной секции заявки главной; одинаковый диалог поверх притемнённой
    страницы читается как диалог в обоих случаях, а перекрашивать окно
    под страницу значило бы завести вторую его версию ради подтверждения
    из трёх строк.
--}}
<div
    x-data="{
        open: false,
        message: '',
        /*
         * Форма, из которой прилетело событие. Нужна, чтобы вернуть фокус
         * на её кнопку отправки при закрытии.
         *
         * Берётся `$event.target`, а не `document.activeElement`: к моменту
         * события кнопка отправки ещё заблокирована на время запроса,
         * а браузер, блокируя элемент с фокусом, отдаёт фокус `<body>`.
         * `activeElement` вернул бы именно его, и Tab после закрытия
         * начинался бы с начала страницы.
         */
        origin: null,
        show(event) {
            this.message = event.detail?.message ?? '';
            this.origin = event.target instanceof HTMLElement ? event.target : null;
            this.open = true;
            this.$nextTick(() => this.$refs.close?.focus());
        },
        hide() {
            if (! this.open) {
                return;
            }

            this.open = false;
            /* Значение селектора без кавычек намеренно: атрибут `x-data`
               уже ограничен двойными, а вложенные пришлось бы писать
               мнемоникой. `submit` — валидный идентификатор CSS. */
            this.origin?.querySelector('button[type=submit]')?.focus();
        },
    }"
    x-on:lead-accepted.window="show($event)"
    x-on:keydown.escape.window="hide()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-200 flex items-center justify-center p-5"
    role="dialog"
    aria-modal="true"
    aria-labelledby="lead-accepted-title"
>
    {{-- Подложка — отдельный элемент, а не фон на контейнере: клик по ней
         закрывает окно, а клик по карточке не должен. --}}
    <div
        x-on:click="hide()"
        class="absolute inset-0 bg-deep/80 backdrop-blur-[6px]"
        aria-hidden="true"
    ></div>

    <div
        x-transition.opacity.duration.200ms
        class="relative w-full max-w-[440px] rounded-card border border-line bg-surface p-8 text-center lg:p-9"
    >
        <button
            type="button"
            x-ref="close"
            x-on:click="hide()"
            class="absolute top-4 right-4 flex size-9 items-center justify-center rounded-field border border-line bg-overlay text-ink-muted transition-colors hover:text-ink"
            aria-label="Закрыть"
        >
            <x-ui.icon name="close" class="size-4" />
        </button>

        <div class="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-accent-solid/14 text-accent">
            <x-ui.icon name="check-circle" class="size-7" />
        </div>

        <h2 id="lead-accepted-title" class="font-display text-[22px] leading-[1.25] font-semibold lg:text-2xl">
            Заявка принята
        </h2>

        {{-- Текст приходит с сервера тем же сообщением, что печатается
             баннером на пути без JavaScript: два источника одной фразы
             разошлись бы молча. --}}
        <p class="mt-3 text-[15px] text-ink-muted" x-text="message"></p>

        <button
            type="button"
            x-on:click="hide()"
            class="mt-7 w-full rounded-full bg-accent-solid py-3.5 text-[15px] font-semibold text-on-accent transition hover:bg-accent-hover"
        >Понятно</button>
    </div>
</div>
