/**
 * Просмотр фотографии автомобиля в полный размер с приближением
 * (веха 4.14, пункт 2 постановки).
 *
 * Регистрация живёт ЗДЕСЬ, в модуле, который `app.js` импортирует до
 * `Alpine.start()`, а не в `@push('scripts')` — по тому же основанию,
 * что и `leadForm`. Правило `RULES.md` запрещает пушнутый скрипт, потому
 * что он исполняется либо раньше модуля из `<head>`, либо уже после
 * старта Alpine; регистрация внутри самого модуля отрабатывает всегда.
 * Инлайновый `x-data` остаётся нормой для коротких компонентов, а сюда
 * код уехал по объёму: зум колесом и щипком, перетаскивание, ловушка
 * фокуса и блокировка прокрутки в атрибут не помещаются.
 *
 * БЕЗ СКРИПТА ЛАЙТБОКС НЕ НУЖЕН И НЕ МЕШАЕТ. Главный кадр и миниатюры
 * обёрнуты в `<a href="{{ $photo->url }}">`, и при неработающем `app.js`
 * клик открывает оригинал штатным просмотрщиком браузера — то самое
 * «в полном размере», которого просил заказчик, просто без зума.
 * Сквозное правило проекта: фильтры каталога работают без скрипта,
 * форма заявки отправляется браузером, галерея деградирует до стопки
 * изображений.
 */

/** Предельное приближение. Дальше растёт не деталь, а пиксель. */
const MAX_SCALE = 4;

/** Шаг зума колесом за один «щелчок». */
const WHEEL_STEP = 0.0015;

/** Приближение по двойному клику. */
const DOUBLE_CLICK_SCALE = 2.5;

export default function photoLightbox(photos = []) {
    return {
        /** Открыт ли просмотр. */
        open: false,

        /** Индекс показываемой фотографии в `photos`. */
        index: 0,

        /** Текущее приближение: 1 — кадр целиком. */
        scale: 1,

        /** Сдвиг приближённого кадра в пикселях. */
        offsetX: 0,
        offsetY: 0,

        /** Идёт ли перетаскивание и с какой точки оно началось. */
        dragging: false,
        dragStartX: 0,
        dragStartY: 0,

        /** Расстояние между пальцами на начало щипка. */
        pinchStart: 0,
        pinchScale: 1,

        /**
         * Элемент, с которого открыли просмотр.
         *
         * Возврат фокуса на него при закрытии — не мелочь: без него
         * клавиатурный посетитель после Escape оказывается в начале
         * страницы и заново идёт табом до галереи.
         */
        opener: null,

        photos,

        get photo() {
            return this.photos[this.index] ?? null;
        },

        get total() {
            return this.photos.length;
        },

        /**
         * Есть ли что приближать.
         *
         * Кнопка зума не предлагается, когда файл не крупнее того, что
         * уже на экране: приближать нечего, а предложение приблизить
         * без эффекта читается как поломка. Ровно ради этого вопроса
         * в вехе 4.14 и появились `width`/`height` в базе — без них
         * ответить на него нечем.
         */
        get zoomable() {
            const photo = this.photo;

            if (photo === null || !photo.width) {
                // Размеров нет — фотография залита до вехи 4.14 и по ней
                // не проходила `images:restamp`. Разрешаем зум: лишняя
                // кнопка честнее, чем отнятая возможность.
                return true;
            }

            return photo.width > window.innerWidth;
        },

        show(index, event) {
            this.opener = event?.currentTarget ?? null;
            this.index = index;
            this.reset();
            this.open = true;

            // Прокрутка страницы под открытым просмотром — это уехавший
            // из-под пальца фон на телефоне и потерянное место на десктопе.
            document.body.style.overflow = 'hidden';

            this.$nextTick(() => this.$refs.dialog?.focus());
        },

        hide() {
            this.open = false;
            document.body.style.overflow = '';

            this.opener?.focus();
            this.opener = null;
        },

        next() {
            this.index = (this.index + 1) % this.total;
            this.reset();
        },

        previous() {
            this.index = (this.index + this.total - 1) % this.total;
            this.reset();
        },

        reset() {
            this.scale = 1;
            this.offsetX = 0;
            this.offsetY = 0;
        },

        /** Зум колесом мыши и тачпадом. */
        onWheel(event) {
            if (!this.zoomable) {
                return;
            }

            event.preventDefault();
            this.zoomTo(this.scale - event.deltaY * WHEEL_STEP);
        },

        /** Двойной клик приближает и возвращает обратно. */
        onDoubleClick() {
            if (!this.zoomable) {
                return;
            }

            this.scale > 1 ? this.reset() : this.zoomTo(DOUBLE_CLICK_SCALE);
        },

        zoomTo(scale) {
            this.scale = Math.min(MAX_SCALE, Math.max(1, scale));

            // На единице кадр вписан целиком, и сдвиг означал бы фото,
            // уехавшее из окна без способа вернуть его.
            if (this.scale === 1) {
                this.offsetX = 0;
                this.offsetY = 0;
            }
        },

        startDrag(event) {
            if (this.scale === 1) {
                return;
            }

            this.dragging = true;
            this.dragStartX = event.clientX - this.offsetX;
            this.dragStartY = event.clientY - this.offsetY;
        },

        onDrag(event) {
            if (!this.dragging) {
                return;
            }

            this.offsetX = event.clientX - this.dragStartX;
            this.offsetY = event.clientY - this.dragStartY;
        },

        endDrag() {
            this.dragging = false;
        },

        /** Щипок двумя пальцами. */
        onTouchStart(event) {
            if (event.touches.length !== 2) {
                return;
            }

            this.pinchStart = this.touchDistance(event);
            this.pinchScale = this.scale;
        },

        onTouchMove(event) {
            if (event.touches.length !== 2 || this.pinchStart === 0) {
                return;
            }

            event.preventDefault();
            this.zoomTo((this.touchDistance(event) / this.pinchStart) * this.pinchScale);
        },

        onTouchEnd() {
            this.pinchStart = 0;
        },

        touchDistance(event) {
            const [first, second] = event.touches;

            return Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY);
        },

        /**
         * Ловушка фокуса: Tab по кругу внутри окна.
         *
         * Без неё Tab уводит на ссылки страницы ПОД просмотром — они
         * видимы для клавиатуры, хотя закрыты оверлеем, и посетитель
         * ходит по невидимым элементам.
         */
        onKeydown(event) {
            if (event.key !== 'Tab') {
                return;
            }

            const focusable = this.$refs.dialog?.querySelectorAll(
                'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
            );

            if (!focusable || focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    };
}
