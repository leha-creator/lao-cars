#!/usr/bin/env bash
#
# Отправка технической тревоги в Telegram. Подключается через `source`
# из `backup.sh` и `healthcheck.sh` — один модуль на обоих, чтобы формат
# сообщения и обработка отказа не разошлись между скриптами.
#
#     source /var/www/laocars/deploy/notify.sh
#     notify "текст"
#
# Токен и чат берутся из `.env` приложения, а не из отдельного файла:
# второй экземпляр токена на диске — это второе место, где его забудут
# сменить при перевыпуске.
#
# ЧАТ ТОТ ЖЕ, ЧТО У ЗАЯВОК, И ПОЭТОМУ ЕСТЬ ПРЕФИКС.
#
# Отдельного чата для техники пока нет. Без пометки в первой строке
# сообщение о забитом диске теряется среди лидов и пролистывается
# как «ещё одна заявка». Первая же пролистанная тревога — названный
# триггер завести отдельный чат и поменять TELEGRAM_ALERT_CHAT_ID.

APP_DIR="${APP_DIR:-/var/www/laocars}"

# Значение из `.env` по имени ключа. Через grep, а не через `artisan`:
# уведомлять чаще всего нужно ровно тогда, когда приложение не поднимается,
# и вызов artisan в этот момент упал бы вместе с ним.
env_value() {
    local key="$1"
    local line

    line="$(grep -E "^${key}=" "${APP_DIR}/.env" 2>/dev/null | tail -n 1)" || true

    if [ -z "${line}" ]; then
        return 0
    fi

    # Снимаем имя ключа и обрамляющие кавычки, если они есть.
    line="${line#*=}"
    line="${line%\"}"
    line="${line#\"}"
    line="${line%\'}"
    line="${line#\'}"

    printf '%s' "${line}"
}

notify() {
    local text="$1"
    local token chat

    token="$(env_value TELEGRAM_BOT_TOKEN)"
    chat="$(env_value TELEGRAM_ALERT_CHAT_ID)"

    # Фолбэк на общий чат заявок: пока отдельного чата нет, тревога
    # обязана дойти хоть куда-то. Молчаливое «некому отправить» здесь
    # хуже сообщения не в том чате.
    if [ -z "${chat}" ]; then
        chat="$(env_value TELEGRAM_MANAGER_CHAT_ID)"
    fi

    if [ -z "${token}" ] || [ -z "${chat}" ]; then
        echo "ВНИМАНИЕ: тревога не отправлена — TELEGRAM_BOT_TOKEN или chat_id пусты. Текст: ${text}"
        return 0
    fi

    # `|| true` и подавленный вывод: недоступный Telegram не должен
    # ронять бэкап или healthcheck — они и так сообщают о беде, а `set -e`
    # в вызывающем скрипте превратил бы сбой отправки в сбой всей задачи.
    #
    # Токен не попадает ни в лог, ни в текст ошибки: curl пишет URL
    # в вывод только при -v, которого здесь нет.
    curl -fsS --max-time 15 \
        -X POST "https://api.telegram.org/bot${token}/sendMessage" \
        -d "chat_id=${chat}" \
        --data-urlencode "text=[ТЕХ] ЛАО КАРС
${text}" \
        > /dev/null 2>&1 || echo "ВНИМАНИЕ: отправка тревоги в Telegram не удалась. Текст: ${text}"
}
