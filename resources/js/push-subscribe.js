/*
 * Подписка браузера сотрудника на push-уведомления (веха 4.7).
 *
 * Отдельная точка входа Vite, а не импорт в `app.js`. Панель Filament
 * работает на собственной сборке (Livewire + свои ассеты) и бандл публичной
 * части не подключает — Alpine из `app.js` в панели попросту нет.
 * Подключается скрипт render hook'ом `PanelsRenderHook::BODY_END`
 * (см. `AdminPanelProvider`).
 *
 * Скрипт обязан МОЛЧА ничего не делать там, где push невозможны:
 * на локальной машине без ключей VAPID панель должна открываться без единой
 * ошибки в консоли — тот же принцип, что у несконфигурированного Telegram
 * вехи 3.7. «Молча» относится к ошибкам, а не к диагностике: причины,
 * которые человек может устранить (незащищённый контекст, отозванное
 * разрешение), пишутся в консоль предупреждением и показываются на экране.
 */

/** Состояния, которые различает интерфейс кабинета. */
const STATE = {
    UNSUPPORTED: 'unsupported', // браузер не умеет push
    INSECURE: 'insecure', // страница не по HTTPS
    UNCONFIGURED: 'unconfigured', // на сервере нет ключей VAPID
    DEFAULT: 'default', // разрешение ещё не спрашивали
    DENIED: 'denied', // разрешение запрещено
    SUBSCRIBED: 'subscribed', // разрешено и подписка есть
    UNSUBSCRIBED: 'unsubscribed', // разрешено, но подписки нет
};

/**
 * Ключ VAPID приходит из разметки: он публичный по назначению, приватный
 * в браузер не попадает никогда.
 */
function publicKey() {
    return document.querySelector('meta[name="vapid-public-key"]')?.content ?? '';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Публичный ключ VAPID приходит строкой base64url, а `pushManager.subscribe`
 * принимает `Uint8Array`. Преобразование обязательное и не сводится
 * к `atob`: в base64url `-` и `_` стоят на местах `+` и `/`, а хвостовые
 * `=` отброшены. Скормить строку как есть — получить `InvalidCharacterError`
 * на ключе, который на сервере абсолютно корректен.
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

/**
 * Можно ли вообще подписаться в этом окружении.
 *
 * Проверяется прежде всего защищённый контекст. Push не работают
 * по `http://` нигде, кроме `localhost`, и это ловушка стейджинга:
 * локально всё работает, на стенде без сертификата кнопка «ничего
 * не делает», и симптом на причину не наводит совершенно.
 */
function environmentState() {
    if (! window.isSecureContext) {
        console.warn(
            '[push] Уведомления недоступны: страница открыта не по HTTPS. '
                + 'Push работают только в защищённом контексте; исключение — localhost.',
        );

        return STATE.INSECURE;
    }

    if (! ('serviceWorker' in navigator) || ! ('PushManager' in window) || ! ('Notification' in window)) {
        return STATE.UNSUPPORTED;
    }

    if (publicKey() === '') {
        // Не ошибка, а штатное состояние локальной машины и CI:
        // ключи VAPID не сгенерированы. Ровно как незаполненный токен
        // Telegram — WARN и выход, а не исключение.
        console.warn('[push] Уведомления выключены: на сервере не заданы ключи VAPID.');

        return STATE.UNCONFIGURED;
    }

    return null;
}

async function registration() {
    // Область `/` обязательна: worker лежит в корне (`public/sw.js`),
    // а уведомление ведёт в `/admin/...`. Worker, зарегистрированный
    // из подкаталога, этот адрес не обслужит.
    return navigator.serviceWorker.register('/sw.js', { scope: '/' });
}

async function send(method, subscription) {
    const response = await fetch('/admin/push-subscriptions', {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(
            method === 'DELETE' ? { endpoint: subscription.endpoint } : subscription.toJSON(),
        ),
    });

    if (! response.ok) {
        throw new Error(`[push] сервер ответил ${response.status}`);
    }

    return response;
}

/**
 * Текущее состояние без единого действия и без единого запроса разрешения.
 *
 * Читается при отрисовке блока в кабинете. «Разрешено, но подписки нет»
 * — это переустановленный браузер или очищенные данные сайта, и выглядеть
 * оно обязано как «не подписан»: иначе человек ждёт уведомлений, которых
 * не будет.
 */
export async function pushState() {
    const blocked = environmentState();

    if (blocked !== null) {
        return blocked;
    }

    if (Notification.permission === 'denied') {
        return STATE.DENIED;
    }

    if (Notification.permission === 'default') {
        return STATE.DEFAULT;
    }

    const existing = await (await registration()).pushManager.getSubscription();

    return existing === null ? STATE.UNSUBSCRIBED : STATE.SUBSCRIBED;
}

/**
 * Подписать этот браузер.
 *
 * Разрешение спрашивается ЗДЕСЬ, то есть по нажатию кнопки человеком,
 * а не при загрузке страницы. Автоматический `requestPermission()`
 * на входе в панель — самый быстрый способ получить «Блокировать»
 * навсегда: отозвать это решение можно только в настройках браузера,
 * и сделает это далеко не каждый.
 */
export async function subscribe() {
    const blocked = environmentState();

    if (blocked !== null) {
        return blocked;
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        console.warn('[push] Разрешение на уведомления не выдано — состояние:', permission);

        return permission === 'denied' ? STATE.DENIED : STATE.DEFAULT;
    }

    const worker = await registration();

    const subscription =
        (await worker.pushManager.getSubscription())
        ?? (await worker.pushManager.subscribe({
            // Обязателен и обязан быть `true`: браузеры отказываются
            // от подписок, чьи уведомления не показываются человеку.
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(publicKey()),
        }));

    await send('POST', subscription);

    return STATE.SUBSCRIBED;
}

/**
 * Отписать этот браузер.
 *
 * Сначала сервер, потом браузер: обратный порядок при упавшем запросе
 * оставил бы строку в базе без подписки на той стороне, и заявки уходили
 * бы в мёртвый адрес до первого 410 от push-сервиса.
 */
export async function unsubscribe() {
    const blocked = environmentState();

    if (blocked !== null) {
        return blocked;
    }

    const subscription = await (await registration()).pushManager.getSubscription();

    if (subscription === null) {
        return STATE.UNSUBSCRIBED;
    }

    await send('DELETE', subscription);
    await subscription.unsubscribe();

    return STATE.UNSUBSCRIBED;
}

// Панель работает на Livewire, и блок настроек может перерисоваться
// без перезагрузки страницы. Функции живут в `window`, чтобы разметка
// кабинета звала их независимо от того, когда она появилась.
window.laocarsPush = { pushState, subscribe, unsubscribe, STATE };
