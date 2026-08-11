/*
 * Service worker для браузерных уведомлений о новой заявке (веха 4.7).
 *
 * Файл лежит в `public/` и НЕ собирается Vite. Причины две, и обе жёсткие:
 * области видимости service worker'а равна каталогу, из которого он отдан
 * (нужен корень `/`), а имя с хешем сборки менялось бы на каждом деплое,
 * то есть браузер регистрировал бы новый worker вместо обновления старого.
 *
 * ЗДЕСЬ НЕТ И НЕ ДОЛЖНО БЫТЬ ОБРАБОТЧИКА `fetch` И НИКАКОГО КЕША.
 *
 * Соблазн «раз уж есть SW, добавим офлайн» отклонён заранее и намеренно.
 * Кеширующий worker переживает деплой: он продолжает отдавать старые
 * ассеты, пока новый манифест Vite ссылается на файлы с другими хешами.
 * Симптом — «сайт сломался у одного человека и чинится только жёстким
 * сбросом» — не воспроизводится больше ни у кого, включая того, кто
 * будет его искать. Цена офлайна здесь несопоставима с выгодой: панель
 * без сети всё равно бесполезна.
 *
 * Значок уведомления — `/notification-icon.png`, 192×192: логотип
 * из `resources/images/logo-white.png` на подложке цвета `--color-page`.
 * Он тоже лежит в `public/`, а не собирается Vite, и по той же причине:
 * этот адрес попадает в полезную нагрузку push, а имя с хешем сборки
 * менялось бы на каждом деплое — уже разосланные уведомления остались бы
 * с битой картинкой. Подложка обязательна: прозрачный логотип на светлой
 * системной теме исчезает целиком.
 */

const DEFAULT_TITLE = 'Новая заявка';

self.addEventListener('push', (event) => {
    if (! event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch (error) {
        // Единственный след того, что уведомление пришло пустым или
        // в неожиданном формате. Без этой записи симптом «прилетело
        // уведомление без текста» не диагностируется вообще: в панели
        // следов нет, на сервере доставка считается успешной.
        console.error('[sw] полезная нагрузка push не разобрана', error);

        return;
    }

    const title = payload.title || DEFAULT_TITLE;

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            icon: payload.icon || '/notification-icon.png',
            badge: payload.badge || undefined,
            // Тег обязателен и строится по id заявки на стороне сервера.
            // Push-сервисы доставляют сообщение повторно при неудачном
            // подтверждении, и без тега одна заявка даёт два всплывающих
            // окна: менеджер решает, что заявок две, и звонит дважды.
            tag: payload.tag || undefined,
            data: payload.data || {},
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url;

    if (! url) {
        return;
    }

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Уже открытая вкладка панели переиспользуется, а не
                // дублируется: `openWindow` на каждое уведомление даёт
                // менеджеру два десятка вкладок админки за день, и это
                // не гипотеза, а прямое следствие десятка заявок.
                for (const client of clientList) {
                    if (client.url === url && 'focus' in client) {
                        return client.focus();
                    }
                }

                // Вкладка панели открыта, но на другой странице: ведём
                // её на карточку заявки вместо новой вкладки.
                for (const client of clientList) {
                    if (client.url.includes('/admin') && 'navigate' in client) {
                        return client.navigate(url).then((navigated) => navigated?.focus());
                    }
                }

                return self.clients.openWindow(url);
            }),
    );
});
