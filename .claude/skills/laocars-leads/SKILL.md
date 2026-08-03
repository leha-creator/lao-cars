---
name: laocars-leads
description: >-
  Приём и обработка заявок (Lead) на сайте ЛАО КАРС: единая сущность с полиморфным
  источником (автомобиль из каталога, услуга, общая форма), Filament-панель лидов со
  статусами и комментариями менеджера, защита публичных форм и Telegram-уведомления
  через очередь Redis. Use when creating or changing lead capture forms, the Lead model
  or migration, lead statuses, the Filament leads resource, or Telegram/queue
  notifications about new leads. Триггеры — заявка, лид, Lead, форма обратного звонка,
  запись на сервис, уведомление менеджеру, Telegram-бот.
license: MIT
metadata:
  author: ai-factory
  version: "1.0"
  domain: backend
  project: laocars
---

# Заявки (Lead) — ЛАО КАРС

Сквозной паттерн всего сайта: каждая форма — на главной, в карточке авто, на странице
услуги, в контактах — создаёт одну и ту же сущность `Lead`. Отличается только источник.

Требования-первоисточники: `.ai-factory/DESCRIPTION.md` и `ТЗ_ЛАО_КАРС.md` (раздел 4 —
«единый список заявок со всех форм сайта с указанием источника»).

## Инварианты

Эти правила не обсуждаются при реализации — на них держится бизнес-ценность сайта.

1. **Лид первичен, уведомление вторично.** Сначала транзакционная запись в БД, только
   потом постановка задачи на уведомление. Недоступность Telegram API не должна ни терять
   заявку, ни отдавать пользователю ошибку.
2. **Одна таблица `leads` на все формы.** Не заводить `car_requests`, `service_requests`,
   `callback_requests` — источник различается полиморфной связью, а не отдельными
   таблицами. Иначе «единый список лидов» в админке превращается в UNION трёх выборок.
3. **Уведомления только через очередь.** Никаких синхронных HTTP-вызовов к внешним API в
   контроллере: время ответа формы не зависит от Telegram.
4. **Каждая публичная форма защищена** — CSRF, `throttle`, honeypot. Формы заявок открыты
   всему интернету и собирают телефоны.
5. **Провал доставки уведомления логируется.** Потерянный лид должен быть диагностируем по
   логам, а не обнаруживаться по звонку клиента «почему мне не перезвонили».

## Модель данных

`Lead` хранит контакт клиента, сообщение, статус обработки и полиморфную ссылку на
источник. Общая форма обратной связи — источник `null`.

```php
enum LeadStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
```

| Поле | Тип | Назначение |
| :---- | :---- | :---- |
| `name`, `phone`, `email` | string | контакт клиента; `phone` обязателен, `email` нет |
| `message` | text, nullable | комментарий клиента из формы |
| `source_type` / `source_id` | morphs, nullable | `Car`, `Service` или `null` для общей формы |
| `status` | enum | `new` / `in_progress` / `closed` |
| `page_url` | string, nullable | откуда пришёл лид — помогает менеджеру и аналитике |

Детали (миграция, модель, связи, скоупы) → [references/lead-model.md](references/lead-model.md)

## Приём заявки

Единый путь для всех форм: `FormRequest` валидирует → сервис пишет лид → задача уходит в
очередь → пользователь получает ответ.

```php
final class StoreLeadController
{
    public function __invoke(StoreLeadRequest $request, LeadService $leads): RedirectResponse
    {
        $leads->capture($request->toDto());

        return back()->with('status', 'Заявка принята — менеджер свяжется с вами.');
    }
}
```

Ключевое в `LeadService::capture()` — порядок и `afterCommit`:

```php
public function capture(LeadData $data): Lead
{
    $lead = DB::transaction(fn (): Lead => Lead::create($data->toArray()));

    // Только после успешного коммита — иначе воркер может забрать задачу
    // раньше, чем транзакция завершится, и не найти лид в БД.
    NotifyManagerAboutLead::dispatch($lead)->afterCommit();

    return $lead;
}
```

Валидация, rate limiting, honeypot и подключение форм в Blade →
[references/forms-and-security.md](references/forms-and-security.md)

## Уведомления

Job с ретраями и обязательным `failed()`. Сбой внешнего API не касается пользователя —
он уже получил подтверждение.

```php
final class NotifyManagerAboutLead implements ShouldQueue
{
    public int $tries = 5;
    public array $backoff = [10, 60, 300, 900];

    public function __construct(private readonly Lead $lead) {}

    public function handle(TelegramNotifier $telegram): void
    {
        $telegram->send($this->lead);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('leads')->error('Lead notification failed permanently', [
            'lead_id' => $this->lead->id,
            'error'   => $e->getMessage(),
        ]);
    }
}
```

Telegram-клиент, формат сообщения, конфиг и обработка ошибок →
[references/notifications.md](references/notifications.md)

## Админка (Filament)

Ресурс `LeadResource` даёт менеджеру единый список со всех форм:

- Колонка «Источник» — человекочитаемая: марка и модель авто, название услуги или
  «Общая форма». Реализуется через `->formatStateUsing()` над полиморфной связью.
- Фильтры по статусу, типу источника и дате — менеджер работает со свежими лидами.
- Статус меняется прямо в таблице; комментарии менеджера — отдельная связанная сущность
  или поле, доступное на странице просмотра.
- Полиморфная связь всегда грузится через `->with('source')` — иначе N+1 на списке.
- Менеджер видит лиды и каталог, но не настройки сайта — это политика доступа, а не
  скрытие пунктов меню.

Готовый ресурс и политики → [references/lead-model.md](references/lead-model.md)

## Тесты

Формы заявок — критичный для бизнеса путь, feature-тесты обязательны. Минимальный набор:

- лид с каждой из трёх форм создаётся и правильно привязывается к источнику;
- невалидный телефон не создаёт лид и возвращает ошибку на форму;
- заполненный honeypot тихо отбрасывает спам;
- при `Queue::fake()` задача уведомления поставлена в очередь;
- **падение Telegram API не теряет лид** — лид в БД, задача уходит в `failed_jobs`, ошибка
  залогирована. Это прямая проверка инварианта №1.

Готовые Pest-тесты → [references/testing.md](references/testing.md)

## Чеклист перед сдачей формы

- [ ] Лид пишется в БД до постановки уведомления, dispatch помечен `afterCommit()`
- [ ] На роуте формы висит `throttle`, в форме — `@csrf` и honeypot
- [ ] Источник заполнен корректно (авто / услуга / `null` для общей формы)
- [ ] `page_url` сохранён
- [ ] Job имеет `tries`, `backoff` и `failed()` с логированием в канал `leads`
- [ ] Список в Filament грузит `source` через eager loading
- [ ] Feature-тест на потерю лида при недоступном Telegram проходит
