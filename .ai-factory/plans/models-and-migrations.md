# План: Модели и миграции (веха 3.2)

**Ветка:** не создаётся (`git.create_branches: false` в `.ai-factory/config.yaml`) — работа в `master`
**Дата создания:** 2026-08-03
**Файл плана:** `.ai-factory/plans/models-and-migrations.md`

## Настройки

| Параметр | Значение |
| :---- | :---- |
| Тесты | Да — тесты моделей, фабрик и сидов |
| Логирование | Standard (INFO по ключевым событиям сидов; слой данных не логируется) |
| Документация | Да — обязательный чекпоинт `/aif-docs` по завершении |
| Хранение фото авто | Своя таблица `car_photos`, без сторонних пакетов |
| Схема `leads` | Полная сразу, включая поля вехи 3.7 |
| Настройки сайта | Таблица key-value `site_settings` + кеш |

## Привязка к роадмапу

**Веха:** «3.2 Модели и миграции» (Этап 3 — Backend, оценка ТЗ: 10–14 дней)

**Обоснование:** план закрывает содержание вехи целиком — `Brand`, `Car`, `CarPhoto`, `Service`, `Lead`, `Employee`, `Review`, настройки сайта, фабрики и сиды. Это фундамент для вех 3.3–3.7 и всего Этапа 4: без схемы данных не начинается ни админка, ни каталог, ни приём заявок.

## Исходное состояние

Каркас вехи 3.1 закрыт и работает. Проверено на момент планирования:

- **Прикладного кода нет.** В `app/Models/` только `User`, миграций три — штатные `users`, `cache`, `jobs` из скелета. Каталогов `app/Enums/`, `app/Services/`, `app/Jobs/`, `app/Policies/` не существует; `app/Enums/` создаётся здесь, остальные — в следующих вехах.
- **Laravel 13.23.0 принёс атрибуты моделей.** `User` уже написан в этом стиле: `#[Fillable([...])]`, `#[Hidden([...])]`. В поставке доступны `Fillable`, `Hidden`, `Guarded`, `Scope`, `ScopedBy`, `UseFactory`, `UsePolicy`, `RouteKey`, `Boot`, `ObservedBy`, `Table` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/`). Атрибута для `casts` нет — касты остаются методом `casts(): array`.
- **`php artisan storage:link` не выполнялся** — симлинка `public/storage` нет, а `/public/storage` числится в `.gitignore`. Фото из сидов без него не отдаются по URL.
- **`assets/cars/` — 46 файлов PNG общим весом 128 МБ**, порядка 3 МБ на файл. Это исходники, а не подготовленные для веба изображения.
- **`storage/app/public/.gitignore` игнорирует содержимое каталога** — засеянные фото в репозиторий не попадут, отдельных правил в корневой `.gitignore` добавлять не нужно.
- **Pint без конфига** — действует пресет `laravel`; шаг `pint --test` в CI обязателен к прогону до пуша.
- **Тесты идут в реальный PostgreSQL** (`laocars_testing`), поэтому `jsonb`, частичные индексы и полиморфные индексы проверяются по-настоящему, а не эмулируются.

## Ключевые решения

**Схема `leads` пишется полностью сразу, одной миграцией.** Веха 3.7 добавляет к заявке поля из макета (способ связи: телефон / WhatsApp / Telegram; удобное время: утро / день / вечер) и поля подбора запчасти (марка, модель, VIN). Все они уже известны — из макета и роадмапа, гадать нечего. Тянуть их в отдельный `ALTER TABLE` значит без нужды множить миграции: 3.7 остаётся вехой про приём и уведомления, а не про схему.

**Фото автомобилей — своя таблица `car_photos`, без `spatie/laravel-medialibrary`.** Laravel 13 и Filament 5 вышли недавно, и совместимость сторонних пакетов с ними требует проверки резолвом — веха 3.1 уже показала, чем это оборачивается. Галерея авто — часть агрегата `Car` с собственным порядком сортировки, ей не нужна общая таблица медиа. Медиабиблиотека с переиспользованием между разделами и ресайз изображений остаются вехе 3.4, где под них будет отдельное решение.

**Главное фото — первое по `sort_order`, без флага `is_main`.** Два источника истины (флаг и порядок) неизбежно расходятся: администратор перетаскивает фото и не понимает, почему на карточке осталось старое. Порядок задаётся drag-and-drop в 3.4, первое и есть главное.

**Настройки сайта — таблица key-value с `jsonb`.** `site_settings`: `key`, `value` (jsonb), `group`. Значения разнородны — от строки телефона до массива из четырёх карточек преимуществ и четырёх тезисов бегущей строки; `jsonb` покрывает и то, и другое без колонки на каждый блок. Доступ — через модель `Setting` с кешем в Redis и сбросом кеша на событиях модели. Сторонний пакет типизированных настроек не заводится по той же причине, что и медиабиблиотека.

**Стиль моделей — атрибуты Laravel 13, а не свойства.** `#[Fillable([...])]` вместо `protected $fillable`, `#[Scope]` над методом вместо префикса `scopeXxx`, `#[UseFactory(...)]` там, где фабрика не находится по конвенции. Так написан `User` — единственная существующая модель проекта, и расходиться с ней смысла нет.

> **Внимание при реализации:** скилл `laocars-leads` (`references/lead-model.md`) содержит готовый код `Lead`, миграции и enum — но в до-13-м стиле (`protected $fillable`, `scopeNew`) и без полей макета. Скилл — источник доменных решений (полиморфный источник, morph map, отдельная таблица комментариев, статусы), но не образец синтаксиса и не полный список колонок. Схема этого плана шире.

**Enum-ы — backed string, а не int.** В БД лежат `in_stock`, `hybrid`, `tire_service` — читаемые значения. Дамп базы и `psql` остаются понятными без справочника, а стоимость по месту на фоне текстовых полей нулевая. Нативные типы PostgreSQL (`CREATE TYPE`) не используются: добавление значения в enum требует миграции типа, а Laravel этим управляет плохо.

**Цена — целые рубли в `unsignedBigInteger`, `null` = «цена по запросу».** Копейки в ценах автомобилей и услуг не встречаются, а `float` для денег — источник ошибок округления. `null` вместо нуля: ноль это бесплатно, а не «уточняйте».

**Полиморфный источник заявки — через morph map.** `Relation::enforceMorphMap(['car' => Car::class, 'service' => Service::class])` в `AppServiceProvider::boot()`. Без неё в `source_type` пишется FQCN, и любой перенос класса в другой namespace молча ломает все существующие строки.

**Комментарии менеджера — отдельная таблица `lead_comments`.** ТЗ требует «возможность оставлять комментарии» во множественном числе; одно текстовое поле в `leads` теряет и историю, и авторство. Таблица заводится здесь вместе с остальной схемой, а работа с ней появится в админке (веха 3.5).

**Индексы — только очевидные, тюнинг в 3.6.** Здесь ставятся: внешние ключи, `slug` (unique), `(status, created_at)` для списков лидов и каталога, одиночные индексы по фильтруемым колонкам (`year`, `engine_type`, `price`) и частичный индекс `WHERE show_on_homepage` для подборки на главной. Частичный индекс — раскрытие того, ради чего выбирался PostgreSQL; в Laravel Schema его нет, ставится через `DB::statement`. Подбор составных индексов под фактические запросы фильтров — веха 3.6, где эти запросы и появятся.

**Динамических характеристик здесь нет.** `CarAttribute` и `CarAttributeValue` — веха 3.3. В `cars` ложатся только фиксированные фильтруемые колонки (марка, модель, год, двигатель, привод, пробег, цена, статус). «Кузов» из макета — уже динамическая характеристика, в схему этой вехи он не входит.

**Сиды делятся на демо-данные и реальные фото.** `CarPhotoSeeder` копирует файлы из `assets/cars/` в `storage/app/public/cars/` — идемпотентно (существующий файл пропускается) и с полным пропуском в тестовом окружении. Иначе каждый прогон `php artisan test` таскает 128 МБ через диск и превращает `RefreshDatabase` в наказание. В тестах фото создаёт `CarPhotoFactory` с фиктивными путями — файлы там не нужны.

## Схема данных

| Таблица | Ключевые колонки | Заметки |
| :---- | :---- | :---- |
| `brands` | `name` uniq, `slug` uniq, `sort_order` | Справочник марок; удаление ограничено при наличии авто |
| `cars` | `brand_id`, `model`, `slug` uniq, `year`, `engine_type`, `engine_volume`, `engine_power`, `drive`, `mileage`, `price`, `status`, `show_on_homepage`, `history`, `description`, `meta_title`, `meta_description`, `sort_order` | `mileage = null` → «Новый»; `price = null` → «по запросу»; meta-поля под SEO вехи 4.3 |
| `car_photos` | `car_id`, `disk`, `path`, `alt`, `sort_order` | Каскадное удаление вместе с авто; главное фото — первое по `sort_order` |
| `services` | `category`, `title`, `slug` uniq, `description`, `price`, `price_note`, `is_published`, `sort_order` | Категория `parts` — запчасти; `price_note` под «от», «за колесо» |
| `leads` | `name`, `phone`, `email`, `message`, `contact_method`, `preferred_time`, `part_brand`, `part_model`, `part_vin`, `source_type`/`source_id`, `status`, `page_url` | Полиморфные колонки nullable — общая форма приходит без источника |
| `lead_comments` | `lead_id`, `user_id`, `body` | Каскад по лиду, `restrictOnDelete` по автору |
| `employees` | `name`, `position`, `bio`, `photo_path`, `is_published`, `sort_order` | Карточки команды на странице «О компании» |
| `reviews` | `author_name`, `author_context`, `body`, `rating`, `photo_path`, `is_published`, `published_at`, `sort_order` | `is_published` по умолчанию `false` — модерация обязательна |
| `site_settings` | `key` uniq, `value` jsonb, `group` | Контакты, соцсети, бегущая строка, промо, преимущества, гарантия, тексты страниц, SEO |

Enum-ы в `app/Enums/`: `CarStatus`, `EngineType`, `DriveType`, `ServiceCategory`, `LeadStatus`, `ContactMethod`, `PreferredTime` — каждый с методом `label()` для русских подписей в UI и админке.

## Задачи

### Фаза 1 — Enum-ы и справочник марок

- [x] **1. Создать enum-ы предметной области**
  `app/Enums/`: `CarStatus` (`in_stock`, `on_order`, `sold`), `EngineType` (`petrol`, `diesel`, `hybrid`, `electric`), `DriveType` (`front`, `rear`, `full`), `ServiceCategory` (`maintenance`, `tire_service`, `detailing`, `extra`, `parts`), `LeadStatus` (`new`, `in_progress`, `closed`), `ContactMethod` (`phone`, `whatsapp`, `telegram`), `PreferredTime` (`morning`, `day`, `evening`).
  Все — backed string, `declare(strict_types=1)`, `final` не ставится (enum и так финален). У каждого метод `label(): string` с русской подписью — это единственный источник подписей для админки и фронта, дублировать их в Blade и Filament нельзя. У `LeadStatus` дополнительно `color(): string` для бейджей Filament (`warning` / `info` / `success`) — значения взять из скилла `laocars-leads`. У `ServiceCategory` — `isParts(): bool`, чтобы страница запчастей не сравнивала со строкой.
  Каталог `app/Enums/` до этой задачи не существует — создаётся здесь.
  Логирование: не требуется, чистые типы без побочных эффектов.
  Проверка: `php artisan tinker` — `App\Enums\CarStatus::InStock->label()` возвращает «В наличии».

- [x] **2. Завести справочник марок: миграция, модель, фабрика** *(зависит от 1)*
  Миграция `create_brands_table`: `id`, `name` (unique), `slug` (unique), `sort_order` (`unsignedSmallInteger`, default 0), `timestamps`.
  `app/Models/Brand.php`: `#[Fillable(['name', 'slug', 'sort_order'])]`, `#[RouteKey('slug')]`, связь `cars(): HasMany`, скоуп `#[Scope] ordered()` — сортировка по `sort_order`, затем по `name`. Генерация `slug` из `name` при создании, если не задан явно.
  `database/factories/BrandFactory.php` — уникальные `name` и производный `slug`.
  Марка отделена от авто, а не хранится строкой в `cars`, ровно ради фильтра каталога: список марок в фильтре должен строиться выборкой из справочника, а не `DISTINCT` по строковой колонке с опечатками наполнителя.
  Логирование: не требуется.
  Проверка: `php artisan migrate`, `Brand::factory()->count(3)->create()` в tinker.

> **Чекпоинт коммита:** `feat: enum-ы предметной области и справочник марок`

### Фаза 2 — Каталог автомобилей

- [x] **3. Миграция и модель `Car`** *(зависит от 2)*
  Миграция `create_cars_table` по схеме из раздела «Схема данных». `brand_id` — `foreignId()->constrained()->restrictOnDelete()`: удаление марки с автомобилями должно падать, а не уносить каталог. `engine_volume` — `decimal(3,1)` nullable (у электро объёма нет), `engine_power` — `unsignedSmallInteger` nullable, `mileage` — `unsignedInteger` nullable (`null` = «Новый», как в макете), `price` — `unsignedBigInteger` nullable в целых рублях. `status` — string с `default('in_stock')`, `show_on_homepage` — boolean `default(false)`.
  Индексы: `slug` unique, `(status, created_at)`, одиночные по `year`, `engine_type`, `price`. Частичный индекс под подборку на главной — через `DB::statement('CREATE INDEX cars_homepage_index ON cars (sort_order) WHERE show_on_homepage')`, в `down()` — `DROP INDEX IF EXISTS`. Schema builder частичные индексы не умеет, поэтому только сырым SQL.
  `app/Models/Car.php`: `#[Fillable([...])]`, `#[RouteKey('slug')]`, касты `status => CarStatus::class`, `engine_type => EngineType::class`, `drive => DriveType::class`, `show_on_homepage => 'boolean'`, `engine_volume => 'decimal:1'`. Связи: `brand(): BelongsTo`, `photos(): HasMany` с сортировкой по `sort_order`, `mainPhoto(): HasOne` (первое по `sort_order`), `leads(): MorphMany`. Скоупы через `#[Scope]`: `inStock()`, `onOrder()`, `available()` (не `sold`), `onHomepage()`. Генерация `slug` из «марка модель год» при создании с добором числового суффикса при коллизии — одинаковые марка + модель + год в каталоге реальны.
  Логирование: не требуется — слой данных.
  Проверка: `php artisan migrate`; в tinker создать авто, убедиться что `slug` сгенерирован и повторное создание того же сочетания даёт другой `slug`; `\DB::select("select indexname from pg_indexes where tablename = 'cars'")` показывает частичный индекс.

- [x] **4. Миграция и модель `CarPhoto`, симлинк хранилища** *(зависит от 3)*
  Миграция `create_car_photos_table`: `id`, `car_id` (`constrained()->cascadeOnDelete()`), `disk` (string, default `'public'`), `path` (string), `alt` (string nullable), `sort_order` (`unsignedSmallInteger`, default 0), `timestamps`; индекс `(car_id, sort_order)`.
  `app/Models/CarPhoto.php`: `#[Fillable([...])]`, связь `car(): BelongsTo`, аксессор `url` через `Storage::disk($this->disk)->url($this->path)`. Собственного флага «главное» нет — главное фото определяется порядком (см. «Ключевые решения»).
  Выполнить `php artisan storage:link` и зафиксировать шаг в README на чекпоинте документации: `public/storage` в `.gitignore`, симлинка в репозитории нет, и на чистом клоне без этой команды фото просто не отдаются. Ошибка проявилась бы только в вехе 4.3 при первом рендере галереи.
  Логирование: не требуется.
  Проверка: `php artisan migrate`; удаление авто в tinker уносит его фото; `public/storage` существует и указывает на `storage/app/public`.

- [x] **5. Фабрики каталога** *(зависит от 4)*
  `database/factories/CarFactory.php`: реалистичные значения — год 2019–2026, пробег до 150 000 или `null`, цена 1.5–9 млн, случайные `EngineType` и `DriveType`, `brand_id` через `Brand::factory()`. Состояния: `inStock()`, `onOrder()` (обнуляет пробег — авто под заказ новое), `sold()`, `onHomepage()`, `withoutPrice()` («цена по запросу»).
  `database/factories/CarPhotoFactory.php` — фиктивный `path` вида `cars/placeholder-{n}.jpg` без обращения к диску: фабрика используется в тестах, где реальные файлы не нужны и только замедляют прогон.
  Модели связать с фабриками по конвенции; там где не находится — `#[UseFactory(...)]`.
  Логирование: не требуется.
  Проверка: `Car::factory()->onHomepage()->has(CarPhoto::factory()->count(4), 'photos')->create()` отрабатывает, порядок фото соответствует `sort_order`.

> **Чекпоинт коммита:** `feat: каталог автомобилей — модели, миграции и фабрики`

### Фаза 3 — Услуги, заявки и контент

- [x] **6. Услуги и запчасти: миграция, модель, фабрика** *(зависит от 1)*
  Миграция `create_services_table`: `id`, `category` (string, индекс), `title`, `slug` (unique), `description` (text nullable), `price` (`unsignedBigInteger` nullable — `null` даёт «цена по запросу»), `price_note` (string nullable — «от», «за колесо»), `is_published` (boolean, default true), `sort_order`, `timestamps`; составной индекс `(category, sort_order)`.
  `app/Models/Service.php`: каст `category => ServiceCategory::class`, скоупы `#[Scope] inCategory(ServiceCategory $category)`, `published()`, `ordered()`, связь `leads(): MorphMany`. Генерация `slug` из `title`.
  Единая сущность на все категории, включая запчасти — решение зафиксировано в `DESCRIPTION.md` и `ARCHITECTURE.md`. Отдельную модель `Part` не заводить ни при каких обстоятельствах: она появится вместе с витриной (артикулы, наличие, фильтры), которой в MVP нет.
  `database/factories/ServiceFactory.php` с состояниями по категориям и `withoutPrice()`.
  Логирование: не требуется.
  Проверка: `Service::inCategory(ServiceCategory::Parts)->published()->ordered()->get()` возвращает только запчасти в заданном порядке.

- [x] **7. Заявки: миграции `leads` и `lead_comments`, модели, morph map** *(зависит от 3, 6)*
  Миграция `create_leads_table` — полная схема (решение зафиксировано выше): `name`, `phone` (32), `email` nullable, `message` (text) nullable, `contact_method` nullable, `preferred_time` nullable, `part_brand` nullable, `part_model` nullable, `part_vin` (17) nullable, `nullableMorphs('source')`, `status` (string, default `'new'`, индекс), `page_url` nullable, `timestamps`; индекс `(status, created_at)` — менеджер работает со списком «новые сверху».
  Миграция `create_lead_comments_table`: `lead_id` (`cascadeOnDelete`), `user_id` (`restrictOnDelete` — автор комментария не удаляется вместе с историей), `body` (text), `timestamps`.
  `app/Models/Lead.php`: касты `status => LeadStatus::class`, `contact_method => ContactMethod::class`, `preferred_time => PreferredTime::class`; связи `source(): MorphTo`, `comments(): HasMany`; скоуп `#[Scope] new()`; метод `sourceLabel(): string` — человекочитаемый источник для админки и Telegram-уведомлений (веха 3.7). `app/Models/LeadComment.php` — связи `lead()` и `author()`.
  В `AppServiceProvider::boot()` добавить `Relation::enforceMorphMap(['car' => Car::class, 'service' => Service::class])`. Это не опциональная оптимизация: без неё в `source_type` пишутся FQCN, и перенос класса ломает существующие строки задним числом.
  `database/factories/LeadFactory.php` с состояниями `forCar()`, `forService()`, `general()` (источник `null` — общая форма), `inProgress()`, `closed()`, `partsRequest()` (заполняет `part_*`).
  Заявка здесь только описывается как данные — приём с форм, валидация, очередь и Telegram относятся к вехе 3.7.
  Логирование: не требуется в этой вехе. Канал `leads` уже настроен в `config/logging.php` и наполнится в 3.7.
  Проверка: в tinker создать лид с источником-авто и лид без источника; в `source_type` лежит `car`, а не FQCN; `sourceLabel()` возвращает «Авто: …» и «Общая форма».

- [x] **8. Команда и отзывы: миграции, модели, фабрики** *(зависит от 1)*
  Миграция `create_employees_table`: `name`, `position`, `bio` (text nullable), `photo_path` nullable, `is_published` (default true), `sort_order`, `timestamps`.
  Миграция `create_reviews_table`: `author_name`, `author_context` nullable («Клиент, импорт авто» — подпись из макета), `body` (text), `rating` (`unsignedTinyInteger` nullable, 1–5), `photo_path` nullable, `is_published` (**default false**), `published_at` (timestamp nullable), `sort_order`, `timestamps`; индекс `(is_published, published_at)`.
  Значение `is_published` по умолчанию именно `false`: ТЗ требует модерации публикации, и отзыв, попадающий на сайт до просмотра администратором, — это дефект, а не удобство.
  Модели `Employee` и `Review` со скоупами `published()` и `ordered()`, фабрики с состояниями `published()` / `pending()`.
  Логирование: не требуется.
  Проверка: `Review::published()->get()` не содержит немодерированных записей.

- [x] **9. Настройки сайта: миграция, модель `Setting`, кеш** *(зависит от 1)*
  Миграция `create_site_settings_table`: `id`, `key` (string, unique), `value` (`jsonb`, nullable), `group` (string, индекс), `timestamps`.
  `app/Models/Setting.php`: каст `value => 'array'`, статические `get(string $key, mixed $default = null): mixed`, `set(string $key, mixed $value, string $group): void`, `group(string $group): array`. Чтение идёт через кеш Redis (`Cache::rememberForever('site_settings', ...)` — одна выборка всех ключей на запрос, а не запрос на каждое обращение из шапки, подвала и главной). Сброс кеша — в событиях `saved` и `deleted` модели, иначе администратор сохраняет настройку и не видит изменений.
  Ключи именуются `<group>.<name>` (`contacts.phone`, `home.ticker`, `seo.default_title`) — группа дублируется в отдельной колонке ради выборки блока целиком и группировки на странице настроек Filament (веха 3.5).
  Логирование: DEBUG при промахе кеша — при отладке страницы настроек важно видеть, читается ли значение из БД или из Redis. Персональные данные и секреты в настройках не хранятся: токен Telegram живёт в переменных окружения (правило из `rules/base.md`).
  Проверка: `Setting::set('contacts.phone', '+7 495 123-45-67', 'contacts')`, затем `Setting::get('contacts.phone')` из свежего процесса tinker; после `Setting::first()->touch()` кеш сброшен.

> **Чекпоинт коммита:** `feat: услуги, заявки, команда, отзывы и настройки сайта`

### Фаза 4 — Сиды, тесты и синхронизация контекста

- [x] **10. Сиды справочников и контента** *(зависит от 2, 6, 8, 9)*
  `database/seeders/`: `BrandSeeder` (марки, реально встречающиеся в импорте из Китая: Zeekr, Voyah, BYD, Li Auto, Exeed, Chery, Haval, Geely — Zeekr, Voyah и BYD прямо названы в ТЗ), `ServiceSeeder` (позиции по всем пяти категориям `ServiceCategory`, часть с ценой, часть с «по запросу»), `EmployeeSeeder` и `ReviewSeeder` (в том числе один немодерированный отзыв — чтобы фильтр модерации было на чём проверить), `SiteSettingSeeder` с ключами из макета: `contacts.*` (телефон, e-mail, адрес «Москва, ул. Осенняя, 17, корп. 1», часы работы), `socials.*` (Telegram, WhatsApp, VK), `home.ticker` (четыре тезиса), `home.promo`, `home.advantages` (четыре карточки), `footer.guarantee` (блок «Гарантия 30 дней»), `services_page.*`, `parts_page.*`, `seo.default_title` / `seo.default_description`.
  Все сиды идемпотентны — через `updateOrCreate` по `slug` или `key`: повторный `db:seed` не должен плодить дубли.
  Подключить в `DatabaseSeeder`, сохранив существующее создание тестового пользователя.
  Это данные для разработки, а не контент заказчика — реальные тексты, цены и перечень категорий запчастей поступают отдельно (см. «Внешние зависимости» в роадмапе).
  Логирование: INFO по завершении каждого сида — сколько записей создано и сколько обновлено. Через `$this->command->info()`, чтобы вывод был виден в консоли.
  Проверка: `php artisan migrate:fresh --seed` отрабатывает; повторный `php artisan db:seed` не увеличивает счётчики.

- [x] **11. Сид автомобилей с реальными фотографиями** *(зависит от 5, 10)*
  `CarSeeder` — 10–12 автомобилей через фабрику поверх марок из `BrandSeeder`: разные статусы, часть с `show_on_homepage`, часть под заказ без пробега, один без цены.
  `CarPhotoSeeder` — копирует изображения из `assets/cars/` в `storage/app/public/cars/` и раскладывает по автомобилям (4–6 фото на карточку, `sort_order` по порядку копирования).
  Два ограничения обязательны, иначе сид становится вредным:
  1. **Пропуск в тестах** — `if (app()->runningUnitTests()) { return; }` с INFO-сообщением. В `assets/cars/` 128 МБ, порядка 3 МБ на файл; копирование на каждом прогоне `RefreshDatabase` сделает тесты неприемлемо медленными.
  2. **Идемпотентность** — существующий файл в `storage/app/public/cars/` пропускается, копируется только отсутствующий.
  Дополнительно предусмотреть отсутствие каталога-источника: на чистом клоне без `assets/` сид должен выдать WARN и завершиться, а не упасть с исключением.
  Исходники — PNG весом около 3 МБ, для веба они не подготовлены. Ресайз и оптимизация относятся к вехе 3.4 вместе с медиабиблиотекой; здесь файлы копируются как есть, и это отмечено в задаче явно, чтобы позже не искать причину тяжёлых страниц каталога.
  Логирование: INFO — сколько файлов скопировано, сколько пропущено как существующие; WARN — если каталог `assets/cars/` не найден.
  Проверка: `php artisan migrate:fresh --seed`; в `storage/app/public/cars/` появились файлы, `Car::with('photos')->get()` отдаёт фото, `php artisan test` не создаёт файлов.

- [ ] **12. Тесты моделей, фабрик и сидов** *(зависит от 11)*
  `tests/Feature/Models/`: `CarTest` (связи `brand` / `photos` / `leads`, скоупы `inStock`, `onHomepage`, `available`, касты enum, генерация `slug` и добор суффикса при коллизии, `mainPhoto` возвращает первое по `sort_order`), `ServiceTest` (`inCategory`, `published`, `ordered`), `LeadTest` (полиморфный источник в обе стороны, morph map пишет `car` / `service`, `sourceLabel()` для трёх случаев, каскадное удаление комментариев), `ReviewTest` и `EmployeeTest` (`published` не отдаёт немодерированное), `SettingTest` (`get`/`set`, чтение из кеша, сброс кеша при сохранении).
  `tests/Feature/Database/SeedersTest.php` — `$this->seed()` отрабатывает, ключевые таблицы непусты, повторный прогон не плодит дубли, файлы на диск не пишутся.
  Именование по `rules/base.md`. `RefreshDatabase` уже подключён в `tests/Pest.php`, драйверы в `phpunit.xml` не трогать — тесты идут в реальный PostgreSQL, и `jsonb` с частичным индексом проверяются по-настоящему.
  Обратимость миграций проверяется вручную, а не автотестом: `php artisan migrate:fresh`, затем `php artisan migrate:rollback` до пустой схемы и снова `php artisan migrate`. Автотест на откат всех миграций медленный и хрупкий, а разовая проверка ловит ровно то, ради чего нужна — забытый `dropIfExists` и `DROP INDEX` частичного индекса.
  Логирование: не требуется.
  Проверка: `php artisan test` зелёный; ручной прогон `migrate:rollback` доходит до пустой схемы без ошибок.

- [ ] **13. Прогнать линтер и актуализировать проектный контекст** *(зависит от 12)*
  `php vendor/bin/pint` по всему новому коду, затем `php vendor/bin/pint --test` — иначе первый же прогон CI упадёт на форматировании, а не на реальной проблеме.
  В `AGENTS.md`: обновить раздел «Текущее состояние» (веха 3.2 закрыта), добавить в структуру проекта `app/Enums/`, перечень моделей и сидов, отметить необходимость `storage:link` в разделе «Запуск окружения», зафиксировать правило о стиле моделей (атрибуты Laravel 13) и о том, что схема `leads` уже содержит поля вехи 3.7.
  В `ROADMAP.md`: отметить веху 3.2 выполненной и перенести в «Завершено» с датой.
  README — на чекпоинте документации: команда `php artisan storage:link` и `php artisan migrate:fresh --seed` для получения рабочего каталога с фото.
  Логирование: не требуется.
  Проверка: `php vendor/bin/pint --test` без замечаний, `php artisan test` зелёный, workflow CI проходит.

> **Чекпоинт коммита:** `docs: актуализация контекста после вехи 3.2`
> **Чекпоинт документации:** `/aif-docs` — README: инициализация БД, сиды и `storage:link`

## План коммитов

| После задач | Сообщение |
| :---- | :---- |
| 1–2 | `feat: enum-ы предметной области и справочник марок` |
| 3–5 | `feat: каталог автомобилей — модели, миграции и фабрики` |
| 6–9 | `feat: услуги, заявки, команда, отзывы и настройки сайта` |
| 10–11 | `feat: сиды демо-данных для разработки` |
| 12 | `test: тесты моделей, фабрик и сидов` |
| 13 | `docs: актуализация контекста после вехи 3.2` |

## Риски

| Риск | Как проявится | Что делать |
| :---- | :---- | :---- |
| Полиморфные связи без morph map | В `source_type` лежит FQCN; перенос класса в другой namespace ломает существующие лиды задним числом | `Relation::enforceMorphMap` в `AppServiceProvider` (задача 7) |
| Сид копирует 128 МБ фото в тестах | `php artisan test` становится неприемлемо медленным, диск забивается | Пропуск `CarPhotoSeeder` в тестовом окружении, фото в тестах — через фабрику (задачи 5, 11) |
| `storage:link` не выполнен | Фото не отдаются по URL; ошибка всплывёт только в вехе 4.3 при первом рендере галереи | Выполнить в задаче 4 и записать в README |
| Схема `Lead` из скилла `laocars-leads` неполна | В админке 3.5 нет полей макета, в 3.7 приходится добавлять миграцию | Схема берётся из этого плана; скилл — источник доменных решений, не колонок (задача 7) |
| Стиль моделей из скилла расходится с `User` | Половина моделей на атрибутах, половина на свойствах | Единый стиль — атрибуты Laravel 13 (решение зафиксировано) |
| Коллизия `slug` при одинаковых марка + модель + год | Уникальный индекс роняет создание второго такого авто в админке | Добор числового суффикса при генерации (задача 3) |
| Частичный индекс не снят в `down()` | Откат миграции падает или оставляет мусорный индекс | `DROP INDEX IF EXISTS` в `down()`, проверяется ручным `migrate:rollback` (задачи 3, 12) |
| Цена в `float` или `decimal` | Ошибки округления в суммах и сравнениях фильтра по цене | Целые рубли в `unsignedBigInteger`, `null` = «по запросу» |
| Отзыв публикуется без модерации | Немодерированный текст попадает на сайт | `is_published` по умолчанию `false` (задача 8) |
| Настройки читаются из БД на каждое обращение | Шапка, подвал и главная дают десятки лишних запросов на страницу | Одна выборка всех ключей в кеш Redis со сбросом на событиях модели (задача 9) |
| Возврат `sqlite` в `phpunit.xml` | `jsonb` и частичные индексы перестают проверяться, тесты зеленеют вхолостую | Драйверы не трогать — правило зафиксировано в `AGENTS.md` |
| Сиды приняты за контент заказчика | На прод уезжают выдуманные марки, цены и отзывы | Сиды запускаются только в разработке; наполнение — этап 5 ТЗ |

## Что НЕ входит в веху

- Справочник динамических характеристик `CarAttribute` и значения `CarAttributeValue`, включая «Кузов» из макета — веха 3.3
- Filament-ресурсы (`CarResource`, `ServiceResource`, `LeadResource` и др.), мультизагрузка фото, медиабиблиотека, ресайз и оптимизация изображений — вехи 3.4–3.5
- Роли администратора и менеджера, политики доступа, страница настроек в админке — веха 3.5
- Фильтры каталога, подбор похожих автомобилей, пагинация, тюнинг составных индексов — веха 3.6
- Приём заявок с форм, `FormRequest`, `LeadService`, очередь Redis, Telegram-уведомления — веха 3.7
- Публичные контроллеры, маршруты, Blade-страницы, микроразметка Product/Vehicle — этап 4
