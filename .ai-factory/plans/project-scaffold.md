# План: Каркас проекта (веха 3.1)

**Ветка:** не создаётся (`git.enabled: false` в `.ai-factory/config.yaml`)
**Дата создания:** 2026-08-02
**Файл плана:** `.ai-factory/plans/project-scaffold.md`

## Настройки

| Параметр | Значение |
| :---- | :---- |
| Тесты | Да — smoke-тесты каркаса |
| Логирование | Verbose (`LOG_LEVEL=debug` локально) |
| Документация | Да — обязательный чекпоинт `/aif-docs` по завершении |
| Локальное окружение | Docker только для PostgreSQL и Redis; PHP/Node локально через Herd |

## Привязка к роадмапу

**Веха:** «3.1 Каркас проекта» (Этап 3 — Backend, оценка ТЗ: 10–14 дней)

**Обоснование:** план полностью закрывает содержание вехи — Laravel + PostgreSQL + Redis, локальное окружение в Docker, Filament, Tailwind, базовый CI на GitHub Actions. Это фундаментальная веха: без неё не стартуют 3.2–3.7 и весь Этап 4.

## Исходное состояние

Репозиторий содержит только ТЗ, исходные фото и контекст AI Factory — кода приложения нет. Проверено на момент планирования:

- **`git init` уже выполнен** — коммит `8aca75f INIT`, remote `origin https://github.com/leha-creator/lao-cars.git`, текущая ветка `master`. Из объёма вехи этот пункт снят; вместо него в план входит приведение `.ai-factory/config.yaml` в соответствие с фактом (задача 10).
- **Локальный инструментарий доступен:** PHP 8.5.4 (Herd), Composer 2.9.5, Docker 29.4.3, Docker Compose v5.1.4, Node 24.15.0, npm 11.12.1.
- Отслеживаются 219 файлов — все относятся к `.claude/` и `.ai-factory/`.

### Факты окружения, проверенные до начала работ

Проверки выполнены на этой машине; условные развилки исходной редакции плана по их результатам сняты.

- **Расширения PHP:** `pdo_pgsql` и `pgsql` есть, **расширения `redis` нет**. Значит `predis/predis` — не запасной вариант, а решение (задача 3).
- **Порт 6379 занят** нативной службой `redis-server` (PID 5240, LISTENING на `0.0.0.0:6379` и `[::]:6379`). Порт 5432 свободен. Redis в Compose публикуется на 56379 (задача 2).
- **Целевые версии зафиксированы прогоном резолва:** `composer create-project laravel/laravel` ставит Laravel 13.8.0 (`"php": "^8.3"`, `laravel/framework: "^13.8"`); `composer update --dry-run` со связкой Filament + Pest на PHP 8.5.4 разрешается без конфликтов в `filament/filament v5.7.5`, `laravel/framework v13.23.0`, `livewire/livewire v4.3.5`, `pestphp/pest v4.7.7`, `pest-plugin-laravel v4.1.0`, `pest-plugin-livewire v4.1.0`.
- **Скелет Laravel 13 уже содержит Tailwind v4** (`tailwindcss ^4.0.0`, `@tailwindcss/vite ^4.0.0`, `vite ^8.0.0`, `laravel-vite-plugin ^3.1`), непустой блок `@theme` в `resources/css/app.css` и загрузку шрифта `Instrument Sans` через `bunny()` в `vite.config.js`. Alpine.js в скелете нет.
- **`composer require pkg:^X.Y` на этой машине не работает как написано:** символ `^` съедается windows-обёрткой composer до разбора аргумента, ограничение вырождается в точную версию `X.Y`. В самом bash символ сохраняется — проблема на стороне обёртки, а не оболочки. Рабочая форма — `pkg:X.*` либо запись ограничения в `composer.json` с последующим `composer update`.

## Ключевые решения

**Окружение: Docker только под базы.** В Docker живут PostgreSQL и Redis, PHP и Node запускаются локально через Herd. На Windows это заметно быстрее — bind mount исходников в контейнер даёт ощутимую просадку по I/O на каждом запросе. Полноценный Docker-образ приложения делается отдельно под прод в вехе 7.1, где он и нужен.

**Тесты ходят в реальные PostgreSQL и Redis, а не в SQLite и array-драйверы.** Смысл smoke-тестов каркаса именно в проверке, что инфраструктура собрана правильно; подмена драйверов скрыла бы ровно те ошибки, которые нужно поймать. Изоляция — через отдельную базу `laocars_testing` и `REDIS_DB=1`.

Решение требует активного действия, а не бездействия: штатный `phpunit.xml` Laravel 13 задаёт `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` и `SESSION_DRIVER=array` **без комментирования**. Не переопределить их — значит получить зелёные тесты, которые не касаются ни PostgreSQL, ни Redis (задача 7).

**`.env.example` — единственный коммитируемый контракт окружения.** Сам `.env` в `.gitignore`, а CI поднимается через `cp .env.example .env`. Поэтому все переключения драйверов делаются в обоих файлах сразу, иначе CI собирается на sqlite и database-драйверах из коробки. При этом порты в `.env.example` остаются каноническими (5432/6379) — локальное смещение Redis на 56379 живёт только в `.env`, потому что сервисы GitHub Actions публикуются на штатных портах.

**Tailwind v4, а не v3 — и он уже в скелете.** Filament v5 требует Tailwind 4.1+, а Laravel 13 приносит его из коробки вместе с плагином `@tailwindcss/vite`. В v4 нет `tailwind.config.js` — конфигурация живёт в CSS через `@theme`. Это прямо определяет форму вехи 4.1: токены дизайн-системы из макета пойдут в CSS, а не в JS-конфиг. Работа задачи 6 — не установка, а вычистка дефолтов скелета (шрифт `Instrument Sans`) и добавление отсутствующего Alpine.js.

**Larastan в базовый CI не входит.** «Базовый CI» из роадмапа — это линтер (Pint, входит в поставку Laravel) и тесты. Статический анализ на пустом проекте даёт шум вместо пользы; добавляется отдельно, когда появится код.

**Пустые каталоги из ARCHITECTURE.md не создаются.** `app/Enums/`, `app/Services/`, `app/Jobs/`, `app/Policies/` появятся вместе с первыми классами в вехах 3.2 и далее. Каталоги с `.gitkeep` без содержимого — шум, а не структура.

## Задачи

### Фаза 1 — Скелет Laravel и локальное окружение

- [x] **1. Установить Laravel в существующий репозиторий**
  Поставить Laravel во временный каталог вне репозитория и перенести содержимое в `D:\work\laocars` — `composer create-project` отказывается работать в непустой директории. Ожидаемая версия — Laravel 13.8.0. Не затирать `.git/`, `.claude/`, `.ai-factory/`, `.idea/`, `assets/`, `AGENTS.md`, `ТЗ_ЛАО_КАРС.md`, `.mcp.json`, `.ai-factory.json`, `skills-lock.json`. Ограничение `"php": "^8.3"` в `composer.json` править не нужно — скелет объявляет ровно его.
  Убрать артефакты SQLite: скрипт `post-create-project-cmd` создаёт `database/database.sqlite` и прогоняет по нему `migrate --graceful`, а в `.gitignore` Laravel записи про sqlite нет — файл переедет вместе с остальным и уйдёт в коммит PostgreSQL-проекта. Удалить его после переноса.
  По `.gitignore`: проверено, что `.ai-factory/`, `.claude/` и `assets/` он не игнорирует, но там есть `/.idea`, а `.idea/.gitignore` и `.idea/vcs.xml` уже отслеживаются в git. Решить осознанно — оставить `/.idea` в игноре (уже отслеживаемые файлы это не затронет) либо исключить строку.
  Проверка: `php artisan --version`, `php artisan about`. Зафиксировать фактические версии — они нужны задачам 5, 9, 10.

- [x] **2. Поднять PostgreSQL и Redis в Docker Compose** *(зависит от 1)*
  `compose.yml`: `postgres:17-alpine` и `redis:7-alpine`, именованные volumes, healthchecks, `restart: unless-stopped`. Init-скрипт `docker/postgres/init/01-create-test-db.sql` создаёт базу `laocars_testing` — она понадобится задаче 7.
  Порты проверены заранее: 5432 свободен и остаётся штатным, **6379 занят нативной службой `redis-server`** — Redis публикуется на `56379:6379`. Останавливать локальную службу не вариант: она поднимется снова после перезагрузки. Смещение отражается только в `.env`; `.env.example` сохраняет канонические 5432/6379 ради CI (задача 9).
  Учесть, что init-скрипты PostgreSQL выполняются исключительно при первичной инициализации пустого volume. Если volume уже существует после неудачного запуска, `laocars_testing` молча не создастся, и задача 7 упадёт с невнятным «database does not exist» — в этом случае пересоздавать через `docker compose down -v`.
  Проверка: `docker compose ps` — оба сервиса healthy; `psql -lqt` показывает обе базы, включая `laocars_testing`; `redis-cli -p 56379 ping` отвечает из контейнера, а не из локальной службы.

- [x] **3. Подключить приложение к PostgreSQL и Redis** *(зависит от 1, 2)*
  Переключить `DB_CONNECTION` на `pgsql`, перевести `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` на redis. Правки вносятся **и в `.env`, и в `.env.example`** — скелет приносит `DB_CONNECTION=sqlite`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `REDIS_CLIENT=phpredis`, а `.env.example` это единственный коммитируемый контракт, из которого собирается CI. В `.env` Redis смотрит на порт 56379, в `.env.example` остаётся 6379.
  Расширения phpredis в этой сборке PHP нет — проверено, повторно выяснять нечего. Ставится `predis/predis`, выставляется `REDIS_CLIENT=predis`; то же решение повторяется в CI (задача 9) и в прод-образе вехи 7.1. Устанавливать в форме `composer require "predis/predis:3.*"` — запись с `^` вырождается в точную версию (см. «Факты окружения»).
  Выполнить `php artisan migrate`.
  Проверка: `DB::connection()->getPdo()`, `Cache::put/get`, `Redis::ping()`, `php artisan queue:work --once`.

- [x] **4. Настроить логирование и отдельный канал заявок** *(зависит от 1)*
  В `config/logging.php` добавить канал `leads` (`daily`, `storage/logs/leads.log`, retention 30 дней, уровень из `LOG_LEVEL`). Выставить `LOG_LEVEL=debug` локально. Зафиксировать правило для вех 3.7 и 4.x: в канал пишутся приём заявки, постановка уведомления в очередь, успех и финальный провал доставки; персональные данные — только id и источник, секреты не логируются никогда.
  Проверка: `Log::channel('leads')->info(...)` создаёт файл.

> **Чекпоинт коммита:** `feat: инициализация Laravel, PostgreSQL и Redis в Docker`

### Фаза 2 — Админка и фронтенд-сборка

- [x] **5. Установить Filament v5 и создать админ-панель** *(зависит от 3)*
  `composer require "filament/filament:5.*" -W`, затем `php artisan filament:install --panels` и `php artisan make:filament-user`. **Форма ограничения принципиальна:** `filament/filament:"^5.0"` на этой машине доходит до composer как точная версия `5.0` и резолв падает — `v5.0.0` заблокирован security-адвизориями `PKSA-nsry-m1tp-jzr9`, `PKSA-317j-243v-z7tc`, `PKSA-3rh1-zh9g-4mq5`. Альтернатива, если захочется каноничного `^5.0`: вписать ограничение прямо в `composer.json` и выполнить `composer update`.
  Панель по пути `/admin`, локаль `ru`, таймзона `Europe/Moscow`, brand name «ЛАО КАРС». Совместимость проверена прогоном резолва до начала работ: Filament v5.7.5 встаёт на Laravel 13.23.0 с Livewire v4.3.5 на PHP 8.5.4 — поднимать или понижать что-либо не требуется. Конкретные ресурсы (`CarResource`, `LeadResource`) относятся к вехам 3.4–3.5 и здесь не создаются.
  Проверка: `/admin/login` отдаёт 200, вход ведёт на дашборд.

- [x] **6. Привести фронтенд-сборку в порядок и создать базовый Blade-layout** *(зависит от 5)*
  Tailwind v4 и Vite устанавливать не нужно — скелет Laravel 13 уже принёс `tailwindcss ^4.0.0`, `@tailwindcss/vite ^4.0.0`, `vite ^8.0.0` и `laravel-vite-plugin ^3.1`, а `resources/css/app.css` уже содержит `@import 'tailwindcss'`. Задача — убедиться в этом и вычистить дефолты под будущую веху 4.1.
  Фактическая работа: поставить Alpine.js (в скелете его нет) и подключить в `resources/js/app.js`; убрать из блока `@theme` дефолтный `--font-sans: 'Instrument Sans'` и удалить блок `fonts: [bunny('Instrument Sans', ...)]` из `vite.config.js` — иначе проект тянет с CDN шрифт, который вехе 4.1 не нужен (там Unbounded и Manrope). Оставить пустую секцию `@theme { }` с пометкой «токены — веха 4.1». Директивы `@source` из скелета сохранить. `tailwind.config.js` в стиле v3 не создавать.
  `resources/views/layouts/app.blade.php` — минимальный каркас со слотами под title/description (задел под SEO вех 4.x). Стили панели Filament не смешивать с фронтовой темой.
  Проверка: `npm run build` и `npm run dev` отрабатывают, `/` рендерится с применённым Tailwind, `window.Alpine` определён, в собранном бандле нет запросов к fonts.bunny.net.

> **Чекпоинт коммита:** `feat: админ-панель Filament и сборка Tailwind`

### Фаза 3 — Тесты и CI

- [x] **7. Настроить Pest и тестовое окружение** *(зависит от 2, 3)*
  Pest + `pest-plugin-laravel` + `pest-plugin-livewire` (последний нужен для тестов Filament в вехах 3.4–3.5). Ставить в форме `composer require --dev "pestphp/pest:4.*" "pestphp/pest-plugin-laravel:4.*" "pestphp/pest-plugin-livewire:4.*"` — запись с `^` вырождается в точную версию (см. «Факты окружения»). Резолв проверен: Pest v4.7.7 и плагины 4.1.0 совместимы с PHPUnit 12.5 из скелета; `allow-plugins` уже разрешает `pestphp/pest-plugin`.
  **Ключевая правка — переопределение `phpunit.xml`.** Скелет Laravel 13 задаёт там `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array` — не закомментированными, а действующими. Просто дописать `DB_DATABASE=laocars_testing` недостаточно: при оставшемся `DB_CONNECTION=sqlite` это значение читается как путь к файлу SQLite, а `QUEUE_CONNECTION=sync` выполняет job синхронно и ломает проверку `Queue::size() > 0` из задачи 8. Итоговый набор: `DB_CONNECTION=pgsql`, `DB_DATABASE=laocars_testing`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `REDIS_DB=1`, `APP_ENV=testing`, `MAIL_MAILER=array`; `DB_URL` оставить пустым. Хост и порт БД приходят из `.env` и здесь не дублируются.
  Подключить `RefreshDatabase` для Feature-тестов.
  Проверка: `php artisan test` проходит, повторный запуск не падает из-за незачищенной БД. Отдельно убедиться, что тесты действительно идут в PostgreSQL: `database/database.sqlite` после прогона не появляется, а в `laocars_testing` видны таблицы миграций.

- [x] **8. Написать smoke-тесты каркаса** *(зависит от 4, 6, 7)*
  `tests/Feature/InfrastructureTest.php` — живое PDO-соединение с PostgreSQL, `Redis::ping()`, кеш через Redis, реальная постановка job в очередь (`Queue::size() > 0`, без `Queue::fake()`). `tests/Feature/SmokeTest.php` — `GET /` отдаёт 200. `tests/Feature/Filament/AdminPanelTest.php` — вход, дашборд, редирект неавторизованного. Именование по `rules/base.md`.
  Проверка: все тесты зелёные; при `docker compose down` падают с внятной ошибкой соединения — это подтверждает, что они действительно ходят в реальную инфраструктуру.

- [x] **9. Собрать базовый CI на GitHub Actions** *(зависит от 8)*
  `.github/workflows/ci.yml`: триггеры на push в `master` и pull request; services `postgres:17` и `redis:7` тех же версий, что в локальном `compose.yml`; `setup-php` с той же версией PHP, что зафиксирована в задаче 1; шаги `composer install` → `cp .env.example .env` → `key:generate` → `npm ci` → `npm run build` → `pint --test` → `php artisan test`. Каждый шаг отдельным `run`, без склейки через `&&` (правило из AGENTS.md).
  Сервисы GitHub Actions публикуются на штатных портах, поэтому `.env.example` обязан хранить 5432 и 6379 — локальное смещение Redis на 56379 остаётся только в `.env`. Иначе `cp .env.example .env` уводит CI на несуществующий порт.
  `REDIS_CLIENT=predis` выставить и в CI: в образах `setup-php` расширения phpredis по умолчанию тоже нет, а несоответствие клиента даст `Class "Redis" not found` уже после зелёной локальной сборки.
  Перед добавлением шага `pint --test` один раз прогнать `./vendor/bin/pint` по коду задач 4–8 — иначе первый же прогон workflow упадёт на форматировании, а не на реальной проблеме.
  Проверка: workflow зелёный после push. Если прогон недоступен — явно указать, что проверка статическая.

> **Чекпоинт коммита:** `test: smoke-тесты каркаса и CI на GitHub Actions`

### Фаза 4 — Синхронизация контекста

- [ ] **10. Актуализировать AGENTS.md и конфиг AI Factory** *(зависит от 9)*
  В `AGENTS.md` убрать «кода нет», отразить фактическую структуру и версии, добавить команды запуска окружения, исправить устаревшее правило про неинициализированный git. В `.ai-factory/config.yaml`: `git.enabled: true`, `git.base_branch: master`; `create_branches` оставить `false`. В `ROADMAP.md` отметить веху 3.1 выполненной и перенести в «Завершено». README не пишется здесь — он создаётся на чекпоинте документации.

> **Чекпоинт коммита:** `docs: актуализация проектного контекста после каркаса`
> **Чекпоинт документации:** `/aif-docs` — README с инструкцией запуска окружения (сейчас README в проекте нет)

## План коммитов

| После задач | Сообщение |
| :---- | :---- |
| 1–4 | `feat: инициализация Laravel, PostgreSQL и Redis в Docker` |
| 5–6 | `feat: админ-панель Filament и сборка Tailwind` |
| 7–9 | `test: smoke-тесты каркаса и CI на GitHub Actions` |
| 10 | `docs: актуализация проектного контекста после каркаса` |

## Риски

| Риск | Как проявится | Что делать |
| :---- | :---- | :---- |
| `composer create-project` в непустом каталоге | Команда падает сразу | Ставить во временный каталог и переносить (задача 1) |
| Дефолты `phpunit.xml` не переопределены | Тесты зелёные, но идут в SQLite и array-драйверы; `Queue::size() > 0` не срабатывает | Заменить все пять переменных драйверов, а не дописывать `DB_DATABASE` (задача 7) |
| `^` в ограничении composer съедается обёрткой | Ограничение вырождается в точную версию; для Filament резолв падает на security-адвизориях | Использовать форму `pkg:X.*` либо править `composer.json` и делать `composer update` (задачи 3, 5, 7) |
| `.env.example` не синхронизирован с `.env` | Локально всё зелёное, CI собирается на sqlite и database-драйверах | Переключать драйверы в обоих файлах; порты в `.env.example` — канонические (задачи 3, 9) |
| Порт 6379 занят нативной службой `redis-server` | `docker compose up` падает на bind либо приложение молча ходит в чужой Redis | Публиковать Redis на 56379 в `compose.yml` и `.env`; локальную службу не останавливать (задача 2) |
| Volume PostgreSQL уже существует | Init-скрипт не отрабатывает, `laocars_testing` нет, задача 7 падает с «database does not exist» | Пересоздать через `docker compose down -v` (задача 2) |
| Переустановка того, что уже в скелете | Дублирование Tailwind/Vite, конфликт версий, лишний шрифт с CDN | Задача 6 проверяет и вычищает дефолты, а не устанавливает заново |
| Конфиг Tailwind в стиле v3 | Токены вехи 4.1 не подхватываются | Конфигурация только через `@theme` в CSS |
| Расхождение версий локалки, CI и прода | Тесты зелёные локально, красные в CI | Версии образов и PHP в CI берутся из задач 1 и 2, а не подбираются заново |

## Что НЕ входит в веху

- Модели, миграции, фабрики и сиды — веха 3.2
- Ресурсы Filament (`CarResource`, `LeadResource` и др.) — вехи 3.4–3.5
- Токены дизайн-системы из макета, layout по UI Kit — веха 4.1
- Прод-образ Docker, Nginx, SSL, воркер под супервизором, бэкапы — веха 7.1
- Токен Telegram-бота и уведомления — веха 3.7
