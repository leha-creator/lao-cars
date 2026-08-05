# AGENTS.md

> Структурная карта проекта для AI-агентов и новых разработчиков. Поддерживайте её в актуальном состоянии при значимых изменениях структуры. Только факты о том, что реально существует.

## Обзор проекта

Сайт «ЛАО КАРС» — импорт автомобилей (Китай, Европа и др.) и автосервисные услуги (ТО, ремонт, шиномонтаж, детейлинг, подбор запчастей). Основной канал получения заявок: каталог авто, страница автосервиса и страница запчастей ведут к заявке, все заявки собираются в единый список лидов в админке.

**Разделы сайта:** Каталог · Автосервис · Запчасти · Контакты (закреплены в меню) плюс «О компании». **Блога в проекте нет** — раздел 3.5 ТЗ исключён, модель статей и WYSIWYG не заводятся. Детейлинг и доп. сервисы — блоки внутри страницы автосервиса, отдельных URL у них нет.

**Текущее состояние: собраны каркас (веха 3.1), схема данных (вехи 3.2–3.3) и админка целиком (вехи 3.4–3.5).** Laravel поднят, подключены PostgreSQL и Redis, настроены сборка фронтенда, тесты и CI. Заведены модели, миграции, фабрики и сиды: каталог с фотографиями, услуги и запчасти, заявки, команда, отзывы, настройки сайта. Поверх фиксированных колонок `cars` работает справочник характеристик (`CarAttribute`) и их значения (`CarAttributeValue`).

В админке Filament работают восемь разделов: автомобили (CRUD, мультизагрузка фото с сортировкой, редактор динамических характеристик), марки, справочник характеристик, медиабиблиотека, услуги и запчасти (вкладки по категориям), команда, отзывы с модерацией и пользователи; плюс страница настроек сайта. Все загрузки проходят через `ImageProcessor`, выбор уже загруженного изображения — через `MediaPicker`. Доступ разделён на администратора и менеджера политиками в строгом режиме авторизации. Публичных страниц ещё нет — это вехи 3.6 и далее.

Подробности — в [.ai-factory/DESCRIPTION.md](.ai-factory/DESCRIPTION.md), планы вех — в [.ai-factory/plans/](.ai-factory/plans/).

## Технологический стек

Версии — фактические, из `composer.lock` и `package.json`.

- **Язык программирования:** PHP 8.5.4 локально (Herd); `composer.json` требует `^8.3`
- **Framework:** Laravel 13.23.0
- **База данных:** PostgreSQL 17 (в Docker)
- **ORM:** Eloquent
- **Админ-панель:** Filament 5.7.5 на Livewire 4.3.5, путь `/admin`
- **Frontend:** Blade + Alpine.js 3 + Tailwind CSS 4, сборка Vite 8, Node 24
- **Очереди и кеш:** Redis 7 (в Docker), клиент `predis` — расширения phpredis нет ни локально, ни в образах CI
- **Тесты:** Pest 4.7.7 с плагинами `laravel` и `livewire`
- **CI:** GitHub Actions — Pint и тесты
- **Интеграции (MVP):** Telegram-бот для уведомлений о заявках (веха 3.7)

## Запуск окружения

В Docker живут только базы: PHP и Node запускаются локально через Herd — bind mount исходников в контейнер на Windows заметно бьёт по I/O.

```bash
docker compose up -d      # PostgreSQL на 5432, Redis на 6380
php artisan migrate
php artisan storage:link  # без него фото каталога не отдаются по URL
php artisan db:seed       # демо-данные: каталог, услуги, отзывы, настройки
npm run dev               # или npm run build
php artisan serve
php artisan queue:work    # обработка очереди заявок
```

`storage:link` входит в `composer setup` — симлинк не хранится в репозитории (`/public/storage` в `.gitignore`), и без него все превью админки отдают 404. Сиды идемпотентны — повторный запуск не плодит дубли; `CarPhotoSeeder` прогоняет фотографии из `assets/cars/` через `ImageProcessor` в `storage/app/public/cars/` (128 МБ PNG → 8,6 МБ WebP с превью) и пропускается в тестовом окружении.

Тесты идут в реальные PostgreSQL и Redis (база `laocars_testing`, `REDIS_DB=1`), поэтому контейнеры должны быть подняты:

```bash
php artisan test
php vendor/bin/pint --test
```

Полный сброс окружения — `docker compose down -v`: init-скрипт, создающий `laocars_testing`, выполняется только на пустом volume.

**Порт Redis смещён на 6380:** штатный 6379 занят нативной службой `redis-server`. Смещение живёт в `compose.yml` и `.env`; `.env.example` хранит канонический 6379, потому что из него собирается CI.

Пятизначный порт для этого не годится: WinNAT резервирует под динамические порты диапазоны вида 55842–56541, и Docker падает с «socket in a way forbidden by its access permissions». Диапазоны переезжают после перезагрузки, поэтому сбой выглядит как «вчера работало». Перед выбором нового порта — `netsh interface ipv4 show excludedportrange protocol=tcp`.

## Структура проекта

Стандартный скелет Laravel; ниже — только то, что добавлено или значимо для проекта.

```
laocars/
├── .ai-factory/              # Контекст AI Factory: спецификация, правила, планы, конфиг
│   ├── config.yaml           # Языки (ru/ru), пути артефактов, git-настройки
│   ├── DESCRIPTION.md        # Спецификация проекта и принятые решения по стеку
│   ├── ARCHITECTURE.md       # Layered на конвенциях Laravel
│   ├── ROADMAP.md            # Вехи по этапам ТЗ
│   ├── plans/                # Планы вех
│   └── rules/base.md         # Конвенции кода проекта
├── .claude/
│   ├── agents/               # Определения субагентов AI Factory
│   └── skills/               # Скиллы: встроенные aif-* + установленные под стек
├── .github/workflows/ci.yml  # CI: Pint и тесты на PostgreSQL и Redis
├── app/
│   ├── Console/Commands/     # MakeAdminCommand: первый администратор на проде
│   ├── Enums/                # CarStatus, EngineType, DriveType, CarAttributeType,
│   │   └── Concerns/         # ServiceCategory, LeadStatus, ContactMethod,
│   │                         # PreferredTime, UserRole, CatalogSort
│   │                         # + HasLabels, HasColors
│   ├── Http/
│   │   ├── Controllers/      # CatalogController: список и карточка авто;
│   │   │                     # LeadController: приём заявок со всех форм;
│   │   │                     # Home/Service/Parts/ContactController —
│   │   │                     # страницы разделов, до вех 4.2–4.5 заглушки
│   │   └── Requests/         # CatalogFilterRequest: валидация GET-контракта,
│   │                         # битый параметр уводит на чистый /catalog;
│   │                         # StoreLeadRequest: валидация формы + toData()
│   ├── Jobs/                 # NotifyManagerAboutLead: уведомление вне
│   │                         # HTTP-цикла, 5 попыток с растущими паузами
│   ├── Filament/             # Админка: NavigationGroup + Resources/<Множ.>/
│   │   ├── Forms/Components/ # MediaPicker: выбор из библиотеки, со связью и без
│   │   ├── Pages/            # ManageSiteSettings: настройки сайта через content()
│   │   └── Resources/        # Cars, Brands, CarAttributes, Media, Services,
│   │                         # Employees, Reviews, Users, Leads — Schemas/,
│   │                         # Tables/, Pages/, Actions/, Concerns/,
│   │                         # RelationManagers/ (комментарии к заявке)
│   ├── Models/               # Brand, Car, CarPhoto, CarAttribute, Media,
│   │   └── Concerns/         # CarAttributeValue, Service, Lead, LeadComment,
│   │                         # Employee, Review, Setting, User + HasSlug
│   ├── Policies/             # AdminOnlyPolicy и StaffPolicy — вся матрица прав
│   │                         # в двух файлах; конкретная политика = одна строка
│   ├── Providers/            # AppServiceProvider: morph map + ImageManager (GD)
│   ├── Providers/Filament/   # AdminPanelProvider: панель /admin, брендинг, локаль,
│   │                         # strictAuthorization, страница профиля
│   ├── Services/             # ImageProcessor + StoredImage: WebP, ресайз, превью;
│   │                         # CatalogCriteria + CatalogFilter + CatalogFilterOptions
│   │                         # + SimilarCars — выборка и опции каталога;
│   │                         # CarStructuredData — JSON-LD карточки;
│   │                         # HomeContent — данные главной;
│   │                         # LeadData + LeadService + TelegramNotifier —
│   │                         # приём заявки и уведомление менеджеру
│   ├── Support/              # ThumbnailPath, MediaSettingKeys, SiteMenu,
│   │                         # SocialLinks, AttributeFilterIndex — чистые
│   │                         # правила без слоя и состояния
│   └── View/Components/      # LeadForm (x-lead-form) — только <form>;
│                             # LeadSection (x-lead-section) — секция-обёртка
│                             # с раскладками; SiteHeader и SiteFooter —
│                             # каркас, читают настройки сами (см. правила)
├── config/images.php         # Пределы обработки изображений и потолок загрузки
├── config/catalog.php        # Карточек на странице, размер блока похожих
│                             # и потолок подборки на главной
├── config/leads.php          # Лимит заявок в минуту с одного IP
├── config/logging.php        # Канал `leads` — отдельный лог пути заявки
├── database/
│   ├── migrations/           # Схема: каталог, услуги, заявки, контент, настройки
│   ├── factories/            # Фабрики всех моделей с состояниями
│   └── seeders/              # Демо-данные; CarPhotoSeeder раскладывает assets/cars
├── docker/postgres/init/     # Init-скрипты Postgres: создание базы laocars_testing
├── resources/
│   ├── css/app.css           # Tailwind v4; @theme — токены дизайн-системы
│   ├── images/               # Логотип, фон хиро (hero-960/1920.webp) и фон
│   │                         # секции заявки (lead-bg.webp); в манифест
│   │                         # попадает через import.meta.glob в app.js
│   │                         # (обязательно eager). Исходники — в assets/
│   ├── js/app.js             # Alpine.js + glob статики вёрстки
│   └── views/
│       ├── layouts/app.blade.php  # Каркас: SEO-секции, @fonts, шапка,
│       │                     # подвал, above-header — бегущая строка главной
│       ├── components/       # lead-form, lead-section, car-card,
│       │                     # page-heading, site-header, site-footer
│       ├── pagination/       # catalog.blade.php — свой вид пагинации,
│       │                     # передаётся именем в links()
│       ├── home/             # index — собрана вехой 4.2
│       ├── services|parts|contacts/  # index — заглушки на каркасе;
│       │                     # наполнение вехами 4.4 и 4.5
│       └── catalog/          # index и show — свёрстаны вехой 4.3
├── tests/
│   ├── Pest.php              # RefreshDatabase, сброс кеша настроек,
│   │                         # resetRateLimiters(), countQueries()
│   └── Feature/              # Infrastructure, Smoke, Filament, Models/*,
│                             # Database/*, Services/*, Http/*, Jobs/*
├── assets/
│   ├── cars/                 # 46 исходных фото автомобилей (IMG_*.PNG) для каталога
│   └── Макет сайта «ЛАО КАРС»/  # Экспорт макета: десктоп, мобильные, UI Kit
├── compose.yml               # PostgreSQL 17 и Redis 7 — только базы
├── phpunit.xml               # Тестовое окружение: pgsql + redis, а не sqlite и array
├── .mcp.json                 # MCP-серверы: postgres, github, filesystem, playwright, chromeDevtools
├── AGENTS.md                 # Этот файл
└── ТЗ_ЛАО_КАРС.md            # Техническое задание заказчика, версия 1.0
```

**Заявка первична, уведомление вторично.** `LeadService::capture()` пишет лид в транзакции и только потом ставит `NotifyManagerAboutLead` в очередь. Telegram недоступен — заявка всё равно в БД, а задача уходит в ретраи; несконфигурированный бот даёт WARN и пропуск без исключения. Инвариант проверяется двумя тестами, и **ни один из них не работает на `queue.default = sync`**: `SyncQueue::handleException()` пробрасывает исключение наружу, то есть упавшая задача выносит пользователю 500 — ровно то, что тест должен опровергать.

**Права живут только в политиках, и панель работает в строгом режиме.** `->strictAuthorization()` в `AdminPanelProvider` меняет умолчание: без политики Filament не разрешает, а бросает `LogicException` при первом обращении. Поэтому у каждой модели, попадающей в панель, политика обязана существовать и реализовывать полный набор методов, включая `reorder` (его дёргают все `reorderable()`-таблицы) и `deleteAny` (его дёргает `DeleteBulkAction`). Базовые классы `AdminOnlyPolicy` и `StaffPolicy` это уже делают — новая политика наследуется от одного из них одной строкой.

**Первый администратор на проде — `php artisan laocars:make-admin`.** Умолчание колонки `users.role` — `manager`, а штатная `make:filament-user` роль не спрашивает: заведённый ею пользователь не сможет попасть ни в настройки, ни в список пользователей, и повысить его будет некому.

## Ключевые точки входа

| Файл | Назначение |
| :---- | :---- |
| ТЗ_ЛАО_КАРС.md | Первоисточник требований: разделы сайта, состав админки, сроки, риски |
| .ai-factory/DESCRIPTION.md | Спецификация: стек, решения по развилкам ТЗ, границы MVP |
| .ai-factory/config.yaml | Конфиг AI Factory: языки, пути, git |
| routes/web.php | Публичные маршруты: `/`, `/catalog`, `/catalog/{slug}`, `/services`, `/parts`, `/contacts`, `POST /leads` |
| resources/css/app.css | Токены дизайн-системы в `@theme` — источник истины по палитре, шрифтам и радиусам |
| vite.config.js | Сборка и самохостинг шрифтов: веса, `subsets` с кириллицей, preload |
| app/Support/SiteMenu.php | Состав навигации: один список на шапку и подвал; пункт без роута выпадает |
| app/Services/CatalogCriteria.php | Единственное место, где имена GET-параметров каталога превращаются в поля |
| app/Services/HomeContent.php | Данные главной: подборка авто, промо, лента, преимущества, SEO; нормализация jsonb-настроек |
| app/Http/Requests/StoreLeadRequest.php | Контракт формы заявки: правила не мягче колонок, honeypot, `page_url` от сервера |
| app/Services/LeadService.php | Приём заявки: запись в транзакции и постановка уведомления |
| app/Services/TelegramNotifier.php | Отправка в Telegram: `e()` на каждом значении, токен не идёт в лог |
| app/View/Components/LeadForm.php | `x-lead-form` — один компонент на все формы заявок сайта; только `<form>`, раскладки в `LeadSection` |
| app/Services/CarStructuredData.php | JSON-LD карточки автомобиля: `Car` + `BreadcrumbList`; печатается с `JSON_HEX_TAG` |
| app/Support/AttributeFilterIndex.php | Длина префикса `left(value, N)`: её берут и миграция, и фильтр, и тест-сторож |
| app/Models/Lead.php | Заявка со всех форм: полиморфный источник, статусы, комментарии |
| app/Models/CarAttribute.php | Справочник динамических характеристик: тип, единица, группа, флаги вывода |
| app/Models/Setting.php | Настройки сайта: key-value с jsonb и кешем в Redis |
| app/Providers/AppServiceProvider.php | Morph map источников заявки (`car`, `service`), синглтон `ImageManager` на GD |
| app/Providers/Filament/AdminPanelProvider.php | Конфигурация админ-панели: путь, брендинг, ресурсы, порядок групп меню, строгий режим авторизации, страница профиля |
| app/Filament/NavigationGroup.php | Разделы меню админки и конвенции раскладки ресурсов Filament v5 |
| app/Policies/AdminOnlyPolicy.php | Матрица прав администратора; парный `StaffPolicy` — права обеих ролей |
| app/Filament/Pages/ManageSiteSettings.php | Страница настроек: реестр ключей, вкладки, сохранение через `data_get` |
| app/Filament/Forms/Components/MediaPicker.php | Выбор изображения из медиабиблиотеки: режим со связью и без |
| app/Console/Commands/MakeAdminCommand.php | `laocars:make-admin` — первый администратор на проде |
| app/Services/ImageProcessor.php | Обработка загрузок: WebP, ресайз, превью; `thumbPathFor()` — правило пути превью |
| database/seeders/DatabaseSeeder.php | Порядок сидов демо-данных |
| compose.yml | Локальные PostgreSQL и Redis |
| phpunit.xml | Тестовое окружение — переопределяет драйверы скелета |
| config/logging.php | Канал `leads`: приём заявки и доставка уведомлений |
| config/leads.php | Лимит заявок в минуту с одного IP |
| .mcp.json | Конфигурация MCP-серверов проекта |
| assets/cars/ | Исходные фото для наполнения каталога |

## Документация

| Документ | Путь | Описание |
| :---- | :---- | :---- |
| README | README.md | Landing-страница: запуск окружения, схема данных и демо-данные, тесты, CI, стек |
| Дизайн-система и каркас | docs/design-system.md | Токены, шрифты, компоненты каркаса, как добавить страницу, диагностика вёрстки |
| Главная страница | docs/homepage.md | Блоки главной, настройки, подборка авто и лимит, замена фоновых фото |
| Каталог: фильтры и URL | docs/catalog.md | GET-параметры каталога, индексация, панель фильтров без JS, галерея, микроразметка карточки |
| Заявки и уведомления | docs/leads.md | Путь заявки до Telegram, настройка бота, воркер, диагностика, работа менеджера |
| Админка каталога | docs/admin-catalog.md | Марки, карточки, фото, характеристики, медиабиблиотека |
| Роли и доступ | docs/admin-roles.md | Права ролей, заведение сотрудников, первый запуск на проде |
| Контент и настройки | docs/admin-content.md | Услуги и запчасти, команда, модерация отзывов, настройки сайта |
| Техническое задание | ТЗ_ЛАО_КАРС.md | Требования заказчика: структура разделов, админка, оценка сроков, риски |
| Спецификация проекта | .ai-factory/DESCRIPTION.md | Стек, архитектурные заметки, что входит и не входит в MVP |
| Правила кода | .ai-factory/rules/base.md | Именование, структура модулей, ошибки, логирование, тесты |
| Роадмап | .ai-factory/ROADMAP.md | Вехи по этапам ТЗ и что уже закрыто |

## AI-контекст

| Файл | Назначение |
| :---- | :---- |
| AGENTS.md | Структурная карта проекта — этот файл |
| .ai-factory/DESCRIPTION.md | Что за проект, какой стек и почему, границы MVP |
| .ai-factory/ARCHITECTURE.md | Layered на конвенциях Laravel: структура папок, правила зависимостей, антипаттерны |
| .ai-factory/rules/base.md | Конвенции кода, обязательные к соблюдению |

## Скиллы проекта

| Скилл | Когда применяется |
| :---- | :---- |
| laocars-leads | Заявки: модель Lead, формы, статусы, Telegram-уведомления через очередь |
| laravel-specialist | Модели, миграции, очереди, общие паттерны Laravel |
| filament-pro | Админка: ресурсы, формы, таблицы, виджеты |
| laravel-security | Защита публичных форм, авторизация, роли, загрузка файлов |
| seo | Микроразметка Vehicle/Product, meta-теги, sitemap каталога |
| tailwind-css-patterns | Адаптивная вёрстка |

## Правила для агентов

- **Не объединять shell-команды через `&&`** — выполнять по одной, чтобы сбой был виден и диагностируем.
  - Неправильно: `composer install && php artisan migrate`
  - Правильно: сначала `composer install`, затем отдельно `php artisan migrate`
- **Python в этом окружении — только `py -3`.** Команда `python3` резолвится в заглушку Microsoft Store из `WindowsApps`, которая печатает «Python» и выходит с кодом 49, не выполнив скрипт. Скан безопасности через `python3` даст ложное «успешно».
- **Git инициализирован, базовая ветка `master`** (`git.enabled: true`). Ветки под планы автоматически не создаются (`create_branches: false`) — работа идёт в `master`.
- **Тесты не подменяют драйверы.** `phpunit.xml` намеренно уводит их в реальные PostgreSQL и Redis. Не возвращайте `sqlite`, `array` и `sync`: с ними прогон зеленеет, не проверяя инфраструктуру, а `Queue::size()` перестаёт что-либо значить. По той же причине в проверках очереди не используется `Queue::fake()`.
- **Конфигурация Tailwind — только через `@theme` в `resources/css/app.css`.** `tailwind.config.js` в стиле v3 не создавать: в v4 его нет, и токены оттуда не подхватятся. Имя нового токена не должно совпадать с ключом другого пространства имён: `--color-*` и `--text-*` дают один класс, и `--color-base` перебил бы размер шрифта `text-base`.
- **Палитра Tailwind по умолчанию обнулена** (`--color-*: initial`, веха 4.3): классов вида `text-gray-400` в сборке нет, цвет берётся только из токенов. `--color-white` и `--color-black` возвращены вручную сразу за обнулением — на `border-white/8` и `bg-white/6` держатся все обводки проекта, и без них они пропадают молча.
- **Alpine пишется инлайном в `x-data`**, а не регистрируется через `Alpine.data()` из `@push('scripts')`: `app.js` приходит модулем из `<head>` и сам вызывает `Alpine.start()`, поэтому пушнутый скрипт исполнится либо раньше модуля, либо после `alpine:init`. Оба варианта ломаются молча. Атрибуты событий — полной формой `x-on:click`, а не `@click`: `@` — префикс директив Blade.
- **Вид пагинации передаётся именем в `links('pagination.catalog')`**, а не ставится через `Paginator::defaultView()`: статик подменяет вид у любого Livewire-компонента с `WithPagination` и не возвращает его обратно.
- **Шрифты подключаются только самохостингом** через блок `fonts` в `vite.config.js`. Тегом `<link>` с внешнего CDN — нет: `bunny()` из скелета убран осознанно, а Google Fonts в макете подключён так, как на живом сайте делать не надо. `subsets` обязан содержать `cyrillic` — умолчание `['latin']` оставляет русский текст без глифов молча.
- **Шапка и подвал читают настройки сами** — единственное исключение из «Blade не ходит в БД», и на страницы оно не распространяется. Обоснование и границы — в `ARCHITECTURE.md` и `docs/design-system.md`.
- **Состав меню правится в одном месте** — `app/Support/SiteMenu.php`. Он же расходится с макетом намеренно: там «Услуги» и «Блог», в проекте «Автосервис» и «Запчасти».
- **Ассеты Filament (`public/css|js|fonts/filament`) в git не попадают** — их публикует `filament:upgrade` на каждом `composer install`.
- **Модели пишутся на атрибутах Laravel 13** — `#[Fillable([...])]`, `#[Scope]` над методом, `#[RouteKey('slug')]`, а не `protected $fillable` и префикс `scopeXxx`. Так написан весь `app/Models/`. Скилл `laocars-leads` показывает код в до-13-м стиле — он источник доменных решений, а не синтаксиса.
- **`key` характеристики — публичный контракт.** По нему строятся GET-параметры фильтра каталога (`?attr[body_type]=Седан`, веха 3.6) и обращения из шаблонов карточки и микроразметки Vehicle (веха 4.3). Свободно правится подпись (`label`), а не ключ: смена `key` ломает сохранённые ссылки на фильтр и код шаблонов.
- **Список значений `select` живёт в `options` справочника**, а не собирается `DISTINCT`-ом по колонке значений: собранный `DISTINCT`-ом список наполняется опечатками наполнителя — «Кроссовер», «кроссовер» и «Кросовер» станут тремя разными кузовами в фильтре. Единственный путь записи значений — `Car::syncAttributeValues()`: там проверка по `options`, нормализация и удаление пустых.
- **Схема `leads` уже содержит поля вехи 3.7** — способ связи, удобное время, марка/модель/VIN для подбора запчасти. Не заводите под них отдельную миграцию: в 3.7 остаётся логика приёма и уведомлений.
- **`WithoutModelEvents` в сидах не возвращать.** Трейт оборачивает прогон в `Model::withoutEvents()`, а на событиях держится генерация slug (иначе NOT NULL на `cars.slug`) и сброс кеша настроек.
- **`fake()->realText()` не использовать.** В локали `ru_RU` он строит цепочку Маркова по большому корпусу и выедает лимит памяти на прогоне тестов. Нужен текст — `fake()->text()`.
- **Заявка не должна теряться** — ключевой инвариант проекта: запись лида в БД первична, уведомление вторично. Детали в скилле `laocars-leads`.
- **Блог не восстанавливать.** ТЗ и макет описывают раздел «Полезно» и пункт меню «Блог» — они отменены. Не заводить модель `Article`, `ArticleResource`, маршруты `/blog` и WYSIWYG под статьи, даже если встретите упоминание в ТЗ.
- **Запчасти — категория `Service`, а не модель `Part`.** Отдельная сущность появится вместе с витриной (артикулы, наличие, фильтры), которой в MVP нет.
- **Русский — в UI, контенте и пояснениях; английский — в коде и доменных терминах** (`Lead`, `Car`, `Service`).
