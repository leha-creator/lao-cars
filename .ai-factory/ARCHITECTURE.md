# Архитектура: Layered Architecture (на конвенциях Laravel)

## Обзор

Слоистая архитектура: запрос идёт строго вниз — `Routes → Controllers → Services → Models/Eloquent → БД`. Каждый слой знает только о слое под собой. Это минимальное разделение ответственности, которое не даёт коду превратиться в спагетти, где HTTP, бизнес-логика и SQL перемешаны в одном методе.

Выбор продиктован реальностью проекта, а не модой: сайт ЛАО КАРС — это в основном CRUD (каталог, услуги и запчасти, команда, отзывы) плюс один по-настоящему логичный путь — приём заявки. Штатный скелет Laravel уже является слоистым, а Filament жёстко ожидает свои ресурсы в `app/Filament/Resources`. Городить поверх этого модули или Domain/Application/Infrastructure значило бы воевать с фреймворком и админкой ради структуры, которую нечем наполнить.

Слои — не бюрократия, а страховка от одной конкретной болезни: «толстый контроллер», в котором и валидация, и запрос к БД, и отправка в Telegram. Именно из таких контроллеров потом теряются заявки.

## Обоснование решения

- **Тип проекта:** контентный сайт с каталогом и приёмом заявок (MVP), см. `.ai-factory/DESCRIPTION.md`
- **Стек:** PHP 8.3+ (локально 8.5), Laravel 13, PostgreSQL 17, Eloquent, Filament 5, Blade + Alpine 3 + Tailwind 4
- **Команда:** один full-stack разработчик
- **Срок:** 5–7 недель при полной занятости
- **Ключевой фактор:** по матрице решений проект однозначно попадает в Layered — команда 1 человек, домен низкой сложности, нагрузка низкая, преимущественно CRUD. Layered даёт максимальную начальную скорость и минимальный порог входа, что решает при таком сроке.

**Осознанный компромисс:** Layered хуже масштабируется по мере роста — сервисы копят несвязанную логику, а по плоской структуре труднее понять, где живёт фича. Для MVP это приемлемо. Триггеры перехода описаны ниже.

## Структура папок

Расширяет штатный скелет Laravel, а не заменяет его.

```
laocars/
├── app/
│   ├── Console/Commands/               # MakeAdminCommand — первый
│   │                                   # администратор на проде
│   ├── Enums/                          # LeadStatus, CarStatus, UserRole,
│   │                                   # ServiceCategory (включая Parts)
│   ├── Filament/
│   │   ├── NavigationGroup.php         # Разделы меню админки (enum)
│   │   ├── Forms/Components/           # MediaPicker — выбор из
│   │   │                               # медиабиблиотеки, со связью и без
│   │   ├── Resources/                  # Раскладка make:filament-resource v5:
│   │   │   ├── Cars/                   #   CarResource.php
│   │   │   │   ├── Schemas/            #   CarForm.php
│   │   │   │   ├── Tables/             #   CarsTable.php
│   │   │   │   ├── Concerns/           #   логика, общая для страниц
│   │   │   │   └── Pages/              #   List / Create / Edit
│   │   │   ├── Brands/                 # те же подпапки + Actions/ —
│   │   │   ├── CarAttributes/          #   действие, нужное и списку,
│   │   │   ├── Employees/              #   и странице редактирования
│   │   │   ├── Media/
│   │   │   ├── Reviews/
│   │   │   ├── Services/
│   │   │   └── Users/
│   │   └── Pages/                      # ManageSiteSettings — страница
│   │                                   # настроек, собрана через content()
│   ├── Http/
│   │   ├── Controllers/                # ── PRESENTATION ──
│   │   │   ├── HomeController.php      # тонкие: приняли → делегировали → отдали
│   │   │   ├── CatalogController.php
│   │   │   ├── ServiceController.php   # страница «Автосервис»
│   │   │   ├── PartsController.php     # посадочная «Запчасти»
│   │   │   └── LeadController.php
│   │   ├── Requests/                   # Валидация входа (FormRequest)
│   │   │   ├── StoreLeadRequest.php
│   │   │   └── CatalogFilterRequest.php
│   │   └── Middleware/                 # Сквозное: throttle, auth
│   ├── Jobs/                           # Фоновые задачи
│   │   └── NotifyManagerAboutLead.php
│   ├── Models/                         # ── DATA ──
│   │   ├── Car.php                     # Eloquent: связи, скоупы, правила модели
│   │   ├── Service.php                 # услуги автосервиса и категории запчастей
│   │   ├── Lead.php
│   │   ├── Employee.php
│   │   └── Review.php
│   ├── Policies/                       # Права: администратор vs менеджер.
│   │                                   # AdminOnlyPolicy и StaffPolicy —
│   │                                   # вся матрица в двух файлах,
│   │                                   # конкретная политика = одна строка
│   ├── Providers/
│   │   └── Filament/                   # AdminPanelProvider: путь панели, брендинг
│   ├── Services/                       # ── BUSINESS LOGIC ──
│   │   ├── ImageProcessor.php          # ресайз, WebP и превью для всех загрузок
│   │   ├── StoredImage.php             # readonly DTO — результат обработки
│   │   ├── LeadService.php             # приём заявки: запись + постановка уведомления
│   │   ├── CatalogCriteria.php         # readonly DTO — фильтр каталога по типам
│   │   ├── CatalogFilter.php           # сборка запроса каталога по фильтрам
│   │   ├── CatalogFilterOptions.php    # варианты формы фильтра
│   │   ├── SimilarCars.php             # подбор похожих для карточки
│   │   └── TelegramNotifier.php        # внешний API
│   ├── Support/                        # Чистые правила без слоя и состояния
│   │   ├── ThumbnailPath.php           # соответствие «оригинал → превью»
│   │   ├── MediaSettingKeys.php        # какие настройки ссылаются на медиа
│   │   └── AttributeFilterIndex.php    # длина префикса индекса left(value, N):
│   │                                   # берут и миграция, и фильтр, и тест
│   └── View/Components/                # Blade-компоненты (x-lead-form и др.)
├── config/
│   ├── images.php                      # обработка загружаемых изображений
│   └── catalog.php                     # карточек на странице, размер блока похожих
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── resources/
│   ├── views/                          # Blade: layouts, pages, components
│   │   ├── layouts/app.blade.php       # каркас: title, description,
│   │   │                               # canonical, robots
│   │   └── catalog/                    # index и show — до вехи 4.3
│   │                                   # функциональные, без дизайна
│   ├── css/
│   └── js/                             # Alpine
├── routes/
│   └── web.php
├── tests/
│   ├── Feature/                        # Формы заявок, фильтры каталога, админка
│   └── Unit/
└── public/
```

**Слоя `Repositories/` здесь нет — и это осознанно.** Eloquent сам по себе реализует Active Record и уже является слоем доступа к данным. Оборачивать `Car::query()` в `CarRepository`, который дублирует API Eloquent, — распространённый карго-культ: он добавляет файл на каждую сущность, ничего не абстрагируя (модель всё равно течёт наружу). Место сложных выборок — скоупы модели и классы-фильтры вроде `CatalogFilter`.

## Правила зависимостей

```
Routes → Controllers → Services → Models → БД
             ↓             ↓
         Requests       Jobs → внешние API
```

- ✅ Контроллер вызывает сервис или (для простого чтения) напрямую модель
- ✅ Сервис вызывает модели, Jobs, другие сервисы
- ✅ Job вызывает сервисы и модели
- ✅ Filament-ресурсы работают с моделями и сервисами
- ❌ Модель не знает о сервисах, контроллерах, Filament-ресурсах и HTTP-запросе — правило, которое ловится первым и уже дважды: правило раскладки превью уехало из `ImageProcessor` в `app/Support/ThumbnailPath.php`, а список медийных ключей настроек — из страницы `ManageSiteSettings` в `app/Support/MediaSettingKeys.php`. В обоих случаях данные нужны и модели, и верхнему слою, и переезд вниз дешевле импорта вверх
- ✅ Модель может пользоваться `app/Support/` — там лежат чистые функции над данными и конфигом, без диска, HTTP и внешнего мира
- ❌ Сервис не знает о контроллерах и не принимает `Request` — только DTO или скаляры
- ❌ Job не вызывается синхронно из контроллера в обход очереди
- ❌ Blade не ходит в БД: никаких `Car::where(...)` в шаблоне
- ❌ Проверок прав внутри Filament-ресурсов нет — права живут только в политиках (`app/Policies/`). Панель работает в строгом режиме авторизации: отсутствующая политика закрывает раздел `LogicException`, а не открывает его всем

**Про «пропуск слоя».** Классический Layered запрещает контроллеру обращаться к данным напрямую. Здесь правило смягчено осознанно: для чистого чтения (`Service::inCategory(ServiceCategory::Parts)->ordered()->get()`) прослойка-сервис — пустой файл, который только переадресует вызов. Сервис появляется там, где есть что оркестрировать: транзакция, несколько шагов, побочный эффект. Для заявки сервис обязателен — в этом весь смысл.

## Взаимодействие слоёв

- **Контроллер → Сервис:** через DTO, а не `Request`. Сервис не должен знать, пришли данные из HTTP-формы, консольной команды или теста.
- **Сервис → внешний мир:** только через Job в очереди. Никаких синхронных HTTP-вызовов в цикле запроса.
- **Контроллер → Blade:** передаются готовые данные (модели, DTO, пагинаторы), а не сырые фильтры для довычисления в шаблоне.
- **Внедрение зависимостей:** через конструктор и контейнер Laravel. Не `new TelegramNotifier()` внутри сервиса — иначе это не подменить в тестах.

## Ключевые принципы

1. **Тонкие контроллеры.** Контроллер валидирует (через FormRequest), делегирует и формирует ответ. Появилось `if` по бизнес-состоянию или расчёт — это уже сервис.
2. **Логика модели живёт в модели.** Скоупы (`scopePublished`, `scopeInStock`), вычисляемые атрибуты, `sourceLabel()` — в Eloquent-модели, а не в сервисе. Иначе модели становятся мешками данных, а сервисы — свалкой.
3. **Сервис — оркестратор.** Загрузить, вызвать метод модели, сохранить, поставить задачу. Он не должен знать про HTTP.
4. **Внешний мир ненадёжен.** Любое обращение к Telegram — через очередь с ретраями. Данные пишутся в БД до попытки уведомления.
5. **Сервисы без состояния.** Данные приходят параметрами, а не копятся в свойствах между запросами.

## Примеры кода

### Тонкий контроллер + сервис (приём заявки)

```php
// app/Http/Controllers/LeadController.php — PRESENTATION
final class LeadController extends Controller
{
    public function store(StoreLeadRequest $request, LeadService $leads): RedirectResponse
    {
        // Валидация уже в FormRequest, логика — в сервисе.
        // Контроллер только переводит HTTP в вызов и обратно.
        $leads->capture(LeadData::fromRequest($request));

        return back()->with('status', 'Заявка принята — менеджер свяжется с вами.');
    }
}
```

```php
// app/Services/LeadService.php — BUSINESS LOGIC
final class LeadService
{
    public function capture(LeadData $data): Lead
    {
        $lead = DB::transaction(fn (): Lead => Lead::create($data->toArray()));

        // Уведомление — после коммита и вне HTTP-цикла.
        // Telegram лежит — заявка всё равно сохранена.
        NotifyManagerAboutLead::dispatch($lead)->afterCommit();

        return $lead;
    }
}
```

Сервис принимает `LeadData`, а не `Request`: тот же метод вызывается из теста и консольной команды без подделки HTTP.

### Логика выборки — в модели, не в контроллере

```php
// app/Models/Car.php — DATA
final class Car extends Model
{
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', CarStatus::InStock);
    }

    public function scopeOnHomepage(Builder $query): Builder
    {
        return $query->where('show_on_homepage', true);
    }
}
```

```php
// app/Http/Controllers/CatalogController.php
public function index(CatalogFilterRequest $request, CatalogFilter $filter, CatalogFilterOptions $options): View
{
    // Сервис получает DTO, а не Request и не сырой массив: тот же фильтр
    // вызывается из теста и консольной команды без подделки HTTP.
    $criteria = $request->toCriteria();

    $cars = $filter->apply(Car::query()->with(['brand', 'mainPhoto']), $criteria)
        ->paginate((int) config('catalog.per_page'))
        ->withQueryString(); // фильтры переживают пагинацию

    return view('catalog.index', [
        'cars' => $cars,
        'options' => $options->build(),
        'criteria' => $criteria,
        'filtered' => $criteria->hasActiveFilters(),
        'canonical' => $this->canonical($cars),
    ]);
}
```

### Нарушение правил зависимостей

```php
// ❌ ПЛОХО: контроллер делает всё сразу
public function store(Request $request)
{
    $request->validate(['phone' => 'required']);          // валидация в контроллере
    $lead = Lead::create($request->all());                 // mass assignment всего подряд

    Http::post("https://api.telegram.org/bot{$token}/sendMessage", [  // ← синхронный
        'text' => "Заявка от {$lead->name}",                          //   внешний вызов
    ]);

    return back();
    // Telegram недоступен → пользователь ждёт таймаут и видит ошибку 500,
    // хотя заявка уже в БД. Клиент отправляет форму снова.
}
```

```php
// ❌ ПЛОХО: модель знает о HTTP
final class Lead extends Model
{
    public function notifyManager(Request $request) { ... }  // ← Request в модели
}
```

## Антипаттерны

- ❌ **Толстый контроллер** — валидация, запросы к БД, вызовы внешних API в одном методе. Главный риск проекта: именно так теряются заявки.
- ❌ **Синхронный вызов Telegram в контроллере** — время ответа формы становится заложником чужого API.
- ❌ **Анемичные модели** — вся логика в сервисах, модели пустые. Скоупы и правила выборки принадлежат модели.
- ❌ **Запросы к БД в Blade** — `@foreach(Car::all() as $car)` в шаблоне. Данные готовит контроллер.
- ❌ **N+1 на списках** — каталог и лиды всегда грузятся с `with()`. Список из 12 авто без eager loading фото — это 13 запросов.
- ❌ **`Repositories/` поверх Eloquent** — прослойка, дублирующая API Eloquent и ничего не абстрагирующая.
- ❌ **Бизнес-правила в middleware** — middleware для сквозного (throttle, auth), а не для доменных проверок.
- ❌ **`env()` вне config-файлов** — вернёт `null` после `php artisan config:cache` на проде.

## Когда пересматривать архитектуру

Layered выбран под MVP. Сигналы, что пора переходить к Structured Modules (модули Catalog / Services / Leads / Content):

- Сервис перевалил за ~500 строк и смешивает несвязанные фичи
- Новая фича стабильно требует правок в 5+ файлах по всему `app/`
- Запчасти перерастают посадочную страницу: появляются артикулы, наличие и фильтры — тогда `Service` разделяется на `Service` и `Part`, и модуль напрашивается сам
- Заходят фичи из раздела 6 ТЗ: личный кабинет, онлайн-запись с календарём слотов, интеграция с CRM, отслеживание доставки
- В проект приходит второй-третий разработчик и вы начинаете мешать друг другу

Путь миграции понятный: сгруппировать существующие контроллеры, сервисы и модели по бизнес-областям в `app/Modules/<Область>/`. Ради этого не нужно ничего переписывать — только перекладывать, поэтому откладывать решение безопасно.
