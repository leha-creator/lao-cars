#!/usr/bin/env bash
#
# Выкат ЛАО КАРС на прод.
#
#     ./deploy/deploy.sh                # origin/master
#     ./deploy/deploy.sh <sha|tag|ref>  # конкретная ревизия, ею же откат
#
# Запускается и вручную по SSH, и из GitHub Actions с ОДНИМ И ТЕМ ЖЕ
# результатом — в аварии чинить придётся одну сущность, а не две.
#
# НИ ОДНОЙ КОМАНДЫ `sudo` ЗДЕСЬ НЕТ И БЫТЬ НЕ ДОЛЖНО.
#
# Это следствие двух решений: пул php-fpm работает от `laocars`, то есть
# от того же пользователя, что и деплой (никаких chown в конце), а opcache
# настроен на `validate_timestamps=1` с `revalidate_freq=0`, то есть
# перезагрузка fpm после выката не нужна. Цена — один stat() на файл
# на запрос, на этом трафике неизмеримая. Выигрыш — CI-пользователю
# не нужно правило в sudoers, и скомпрометированный CI не даёт root.
#
# `--seed` не появляется здесь никогда: `CarSeeder` и `ServiceSeeder`
# наливают демо-контент, и двенадцать выдуманных автомобилей на живом
# сайте хуже пустого каталога, потому что выглядят настоящими.

set -euo pipefail

REF="${1:-origin/master}"
APP_DIR="/var/www/laocars"
LOG_DIR="/var/log/laocars"
LOG_FILE="${LOG_DIR}/deploy.log"
DOMAIN="laocars.ru"

mkdir -p "${LOG_DIR}"

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

# Работа вынесена в функцию, а весь её вывод уходит одним конвейером
# в `tee` (см. последние строки файла). Прежний вариант с подстановкой
# процесса (`exec > >(... | tee)`) при выходе не дожидается, пока
# приёмник допишет буфер, и обрезает ПОСЛЕДНИЕ строки — то есть ровно
# ту, ради которой лог и открывают: «завершён» или «упал на шаге N».
# Здесь код возврата берётся из `PIPESTATUS[0]`, поэтому `tee`
# не превращает падение выката в успех.
deploy() {

cd "${APP_DIR}"

log "=== Выкат начат: ref=${REF} ==="

# Сайт не должен остаться в maintenance из-за упавшего шага. `|| true`
# потому, что при падении ДО `down` приложение и так поднято, а вторая
# `up` идемпотентна.
trap 'php artisan up || true' EXIT

git fetch --prune --tags origin

# Проверка ДО того, как что-либо погашено. Опечатка в ref не должна
# гасить сайт впустую: без этой строки `git reset` упал бы уже после
# `artisan down`, и посетители увидели бы 503 из-за опечатки.
if ! git rev-parse --verify --quiet "${REF}^{commit}" > /dev/null; then
    log "ОШИБКА: ревизия '${REF}' не найдена после fetch. Ничего не тронуто."
    exit 1
fi

PREVIOUS_SHA="$(git rev-parse HEAD)"
TARGET_SHA="$(git rev-parse "${REF}^{commit}")"

log "Текущая ревизия:   ${PREVIOUS_SHA}"
log "Целевая ревизия:   ${TARGET_SHA}"
log "ОТКАТ НА ПРЕЖНЮЮ: ./deploy/deploy.sh ${PREVIOUS_SHA}"

if [ "${PREVIOUS_SHA}" = "${TARGET_SHA}" ]; then
    log "Ревизия уже развёрнута — но выкат продолжается: зависимости и сборка могли не доехать."
fi

# ПОРЯДОК НИЖЕ — ГЛАВНОЕ В ЭТОМ ФАЙЛЕ, И ОН НЕ ПРОИЗВОЛЕН.
#
# `composer install` вызывает `artisan filament:upgrade` через
# `post-autoload-dump`, то есть ПОДНИМАЕТ приложение. Между `git reset`
# и `composer install` на диске лежит новый код со СТАРЫМ `vendor/`:
# любая артизан-команда в этом промежутке падает, как только релиз
# добавил зависимость или сервис-провайдер.
#
# Поэтому `down` и `optimize:clear` идут ДО `git reset` — они
# выполняются на старом коде, который заведомо бутается. Обратный
# порядок (сначала reset) означал бы, что первой такой командой
# оказывается сама `php artisan down`: она бы не сработала, `trap`
# с `artisan up` не починил бы ничего, и сайт отдавал бы 500 всем
# посетителям весь `composer install` и `npm run build` — около двух
# минут вместо штатной заглушки.
#
# Требование «`optimize:clear` до `composer install`» при этом
# сохраняется: стухший кеш конфига с ключом, которого нет в новом коде,
# роняет `filament:upgrade` в середине выката.
php artisan down --retry=15

php artisan optimize:clear

git reset --hard "${TARGET_SHA}"
# `reset --hard`, а не `pull`: pull на разошедшейся истории оставляет
# сервер в конфликте посреди выката. Локальных правок на сервере нет
# по устройству — `.env`, `storage/` и `public/build` вне git.

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# КЕШ ШРИФТОВ ПЕРЕЖИВАЕТ `npm ci`, И БЕЗ ЭТОГО ВЫКАТЫ ПАДАЮТ СЛУЧАЙНО.
#
# `vite.config.js` скачивает woff2 на сборке через `google()` из
# `laravel-vite-plugin/fonts` и кеширует их в
# `node_modules/.cache/laravel-vite-plugin/fonts`. `npm ci` сносит
# `node_modules` целиком — то есть вместе с кешем, — и каждый выкат
# идёт в сеть за шрифтами заново.
#
# Это не теория: 14.08.2026 выкат упал с
# `Failed to fetch "https://fonts.gstatic.com/s/unbounded/…woff2":
# 404 Not Found`, а следующий на той же ревизии прошёл. То есть отказ
# перемежающийся, и вызывает его сторона, к изменению отношения
# не имеющая. В CI ровно этот каталог кешируется отдельным шагом
# (`actions/cache` в ci.yml) — здесь он кешируется так.
#
# Цена промаха высокая и незаметная: `vite` падает на `buildStart`,
# до записи вывода, поэтому прежний `public/build` остаётся цел
# и сайт продолжает отдавать 200 со СТАРЫМИ ассетами при уже
# обновлённом коде. Расхождение кода и сборки не видно ничем.
#
# Хранилище — в домашнем каталоге деплой-пользователя, а НЕ в /var/cache:
# туда `laocars` писать не может, а `sudo` в этом скрипте запрещён
# по устройству (решения 6 и 7). Путь в /var/cache уронил бы выкат
# на самом `mkdir`, то есть починка обернулась бы поломкой.
FONT_CACHE_SRC="node_modules/.cache/laravel-vite-plugin/fonts"
FONT_CACHE_KEEP="${HOME}/.cache/laocars-vite-fonts"

mkdir -p "$(dirname "${FONT_CACHE_KEEP}")"

if [ -d "${FONT_CACHE_SRC}" ]; then
    rm -rf "${FONT_CACHE_KEEP}"
    cp -a "${FONT_CACHE_SRC}" "${FONT_CACHE_KEEP}"
    log "Кеш шрифтов сохранён перед npm ci."
fi

# `npm ci`, а не `npm install`: ставит ровно то, что в package-lock.json.
# NODE_ENV=production в окружении задавать НЕЛЬЗЯ — `vite`
# и `tailwindcss` лежат в devDependencies, и с этой переменной `npm ci`
# их пропустит, а сборка упадёт на отсутствующем `vite`.
npm ci

if [ -d "${FONT_CACHE_KEEP}" ]; then
    mkdir -p "$(dirname "${FONT_CACHE_SRC}")"
    rm -rf "${FONT_CACHE_SRC}"
    cp -a "${FONT_CACHE_KEEP}" "${FONT_CACHE_SRC}"
    log "Кеш шрифтов возвращён на место — сборка не пойдёт в сеть."
fi

npm run build

php artisan migrate --force

# Идемпотентно: симлинк уже есть, команда просто подтверждает его.
php artisan storage:link

php artisan optimize          # config + route + view + event
php artisan filament:optimize # компоненты панели и кеш иконок

# Воркер доделает текущую задачу и выйдет, systemd поднимет заново.
php artisan queue:restart

php artisan up

# ПРОВЕРКА ИДЁТ ПО HTTPS ЧЕРЕЗ --resolve, А НЕ ПО http://127.0.0.1.
#
# После выпуска сертификата блок на порту 80 отдаёт 301, а `curl -f`
# на 3xx не падает: без `-L` проверка завершилась бы успехом, не дойдя
# до PHP вообще. То есть выкат объявлял бы себя удачным при мёртвом
# php-fpm. `--resolve` бьёт в петлю, но настоящим именем — заодно
# проверяется сертификат и весь путь целиком.
#
# До выпуска сертификата эта строка ЗАКОНОМЕРНО падает; на первом
# ручном запуске (задача 8 плана) её пропускают через SKIP_HEALTHCHECK=1.
if [ "${SKIP_HEALTHCHECK:-0}" = "1" ]; then
    log "ВНИМАНИЕ: финальная проверка пропущена (SKIP_HEALTHCHECK=1). Допустимо только до выпуска сертификата."
else
    curl -fsS --max-time 15 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up" > /dev/null
    log "Проверка https://${DOMAIN}/up пройдена."
fi

log "=== Выкат завершён: ${PREVIOUS_SHA} -> ${TARGET_SHA} ==="

}

# Единственный конвейер на весь скрипт.
#
# Код возврата доносит `pipefail` из `set -euo pipefail`: без него
# наружу уходил бы статус `tee`, то есть всегда ноль, и упавший выкат
# выглядел бы удачным для GitHub Actions. При падении `deploy` статус
# конвейера ненулевой, и `errexit` завершает скрипт прямо здесь — до
# строки `exit` ниже; она отрабатывает только на успешном выкате.
# Обойтись без `pipefail`, подставив `${PIPESTATUS[0]}`, нельзя:
# снятие `set -e` ради этого выключило бы errexit и ВНУТРИ `deploy`,
# то есть упавшая миграция перестала бы останавливать выкат.
deploy 2>&1 | tee -a "${LOG_FILE}"
exit "${PIPESTATUS[0]}"
