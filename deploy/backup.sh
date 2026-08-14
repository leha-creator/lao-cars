#!/usr/bin/env bash
#
# Бэкап ЛАО КАРС: база ежедневно, медиа еженедельно, конфигурация каждый раз.
#
#     ./deploy/backup.sh          # база + конфигурация
#     ./deploy/backup.sh --media  # то же плюс архив медиа
#
# Cron (от пользователя laocars):
#     30 3 * * *  /var/www/laocars/deploy/backup.sh          >> /var/log/laocars/backup.log 2>&1
#     30 4 * * 0  /var/www/laocars/deploy/backup.sh --media  >> /var/log/laocars/backup.log 2>&1
#
# КОПИИ ЛЕЖАТ НА ТОМ ЖЕ ДИСКЕ, И ЭТО ЗАПИСАННЫЙ ДОЛГ, А НЕ НЕДОСМОТР.
#
# Они спасают от «удалили не ту запись» и «сломали миграцию», но не от
# потери сервера. Вынос копии наружу — названный следующий шаг
# с триггером: первый же реальный контент в каталоге.
#
# НЕПРОВЕРЕННЫЙ БЭКАП — ЭТО ПРЕДПОЛОЖЕНИЕ, А НЕ БЭКАП. Разовая проверка
# восстановления описана в docs/deploy.md и делается руками.

set -euo pipefail

APP_DIR="/var/www/laocars"
BACKUP_DIR="/var/backups/laocars"
COMPOSE_FILE="${APP_DIR}/compose.production.yml"
STAMP="$(date '+%Y%m%d-%H%M')"

# Сроки хранения. База чаще и дешевле, медиа реже и на порядок тяжелее.
KEEP_DB_DAYS=14
KEEP_MEDIA_DAYS=28
KEEP_CONFIG_DAYS=5

source "${APP_DIR}/deploy/notify.sh"

# Любое падение — сообщение в Telegram. Молчаливо не сработавший бэкап
# хуже отсутствующего: на него рассчитывают.
trap 'notify "БЭКАП УПАЛ на строке ${LINENO}. Смотреть /var/log/laocars/backup.log"' ERR

mkdir -p "${BACKUP_DIR}/db" "${BACKUP_DIR}/media" "${BACKUP_DIR}/config"
chmod 700 "${BACKUP_DIR}" "${BACKUP_DIR}/config"

echo "=== Бэкап начат: ${STAMP} ==="

# --- База ------------------------------------------------------------
#
# Формат `-Fc` (custom), а не простой SQL: он сжат и позволяет
# восстанавливать выборочно отдельные таблицы через pg_restore.
#
# `exec -T` обязателен: без него docker выделяет псевдотерминал,
# и дамп приезжает с подменёнными переводами строк — файл выглядит
# целым, а pg_restore на нём падает.
#
# `< /dev/null` тоже обязателен, и это не перестраховка. `exec -T`
# ПОДКЛЮЧАЕТ stdin к контейнеру, поэтому запуск скрипта из другого
# скрипта, читающего stdin (`bash -s <<EOF`, ssh с heredoc), приводит
# к тому, что pg_dump съедает остаток вызывающего скрипта. Симптом
# читается как «команда молча оборвалась на середине» и ищется где
# угодно, кроме перенаправления. Поймано ровно так при проверке
# восстановления.
DB_USER="$(grep -E '^POSTGRES_USER=' "${APP_DIR}/deploy/.env.docker" | cut -d= -f2-)"
DB_NAME="$(grep -E '^POSTGRES_DB=' "${APP_DIR}/deploy/.env.docker" | cut -d= -f2-)"
DB_FILE="${BACKUP_DIR}/db/laocars-${STAMP}.dump"

docker compose -f "${COMPOSE_FILE}" exec -T postgres \
    pg_dump -U "${DB_USER}" -Fc "${DB_NAME}" > "${DB_FILE}" < /dev/null

# Пустой дамп — это отказ, который иначе заметят при восстановлении.
if [ ! -s "${DB_FILE}" ]; then
    rm -f "${DB_FILE}"
    notify "БЭКАП БАЗЫ ПУСТ — файл удалён, копии за ${STAMP} нет."
    exit 1
fi

echo "База: ${DB_FILE} ($(du -h "${DB_FILE}" | cut -f1))"

# --- Конфигурация ----------------------------------------------------
#
# `.env` бэкапится при каждом запуске и это не перестраховка: там
# APP_KEY и пара VAPID. Потеря первого делает нечитаемыми сессии и все
# зашифрованные значения; потеря второй тихо убивает все подписки
# сотрудников, причём кнопка в кабинете по-прежнему будет показывать
# «этот браузер подписан».
CONFIG_FILE="${BACKUP_DIR}/config/env-${STAMP}"
cp "${APP_DIR}/.env" "${CONFIG_FILE}"
cp "${APP_DIR}/deploy/.env.docker" "${BACKUP_DIR}/config/env.docker-${STAMP}"
chmod 600 "${BACKUP_DIR}/config/"*

echo "Конфигурация: ${CONFIG_FILE}"

# --- Медиа (только по флагу) -----------------------------------------
#
# Ежедневно не нужно: медиа меняется редко и весит на порядок больше
# базы, а 40 ГБ диска делят ещё vendor, node_modules и volume баз.
if [ "${1:-}" = "--media" ]; then
    MEDIA_FILE="${BACKUP_DIR}/media/media-${STAMP}.tar.gz"

    # Каталог диска `public` — физически `storage/app/public`, наружу
    # он отдаётся симлинком `public/storage`. Бэкапится источник,
    # а не симлинк.
    tar -czf "${MEDIA_FILE}" -C "${APP_DIR}/storage/app" public

    echo "Медиа: ${MEDIA_FILE} ($(du -h "${MEDIA_FILE}" | cut -f1))"
fi

# --- Ротация ---------------------------------------------------------
find "${BACKUP_DIR}/db"     -type f -name '*.dump'    -mtime "+${KEEP_DB_DAYS}"     -delete
find "${BACKUP_DIR}/media"  -type f -name '*.tar.gz'  -mtime "+${KEEP_MEDIA_DAYS}"  -delete
find "${BACKUP_DIR}/config" -type f -name 'env*'      -mtime "+${KEEP_CONFIG_DAYS}" -delete

echo "=== Бэкап завершён: ${STAMP} ==="
