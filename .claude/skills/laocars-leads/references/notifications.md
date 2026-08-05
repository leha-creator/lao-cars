# Уведомления о заявке: очередь + Telegram

Инвариант: **лид уже в БД, уведомление — попытка доставить его быстрее**. Любой сбой здесь
не касается ни пользователя, ни сохранности заявки.

## Конфигурация

```php
// config/services.php
'telegram' => [
    'token'   => env('TELEGRAM_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_MANAGER_CHAT_ID'),
],
```

```dotenv
# .env.example — реальные значения только в .env, он не коммитится
TELEGRAM_BOT_TOKEN=
TELEGRAM_MANAGER_CHAT_ID=

QUEUE_CONNECTION=redis
```

Токен читается только через `config('services.telegram.token')`. Прямой `env()` вне
config-файлов ломается при `php artisan config:cache` на проде — вернёт `null`.

## Отдельный лог-канал

Потерянный лид должен быть диагностируем.

```php
// config/logging.php
'leads' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/leads.log'),
    'level'  => 'info',
    'days'   => 90,
],
```

## Клиент

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramNotifier
{
    public function send(Lead $lead): void
    {
        $token  = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');

        if (blank($token) || blank($chatId)) {
            // Не сконфигурировано — не повод сыпать ретраями
            Log::channel('leads')->warning('Telegram is not configured, notification skipped', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        // `->retry()` у клиента не ставится: повторы принадлежат задаче
        // ($tries = 5 и $backoff). Retry здесь вместе с ретраями задачи
        // дал бы до пятнадцати обращений к API на один лид и растянул бы
        // «мгновенное уведомление» на минуты внутри одной попытки.
        $response = Http::timeout((int) config('services.telegram.timeout'))
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $this->format($lead),
                'parse_mode' => 'HTML',
            ]);

        // Бросаем исключение — Job уйдёт в ретрай по своему backoff.
        // В сообщение идут только статус и тело ответа: URL запроса
        // содержит токен, и он унёс бы его в лог — файл, который живёт
        // 30 дней и попадает в бэкапы.
        if ($response->failed()) {
            throw new RuntimeException(
                "Telegram API error: {$response->status()} {$response->body()}"
            );
        }

        Log::channel('leads')->info('Lead notification delivered', ['lead_id' => $lead->id]);
    }

    private function format(Lead $lead): string
    {
        return implode("\n", array_filter([
            '🚗 <b>Новая заявка — ЛАО КАРС</b>',
            '',
            '<b>Источник:</b> ' . e($lead->sourceLabel()),
            '<b>Имя:</b> ' . e($lead->name),
            '<b>Телефон:</b> ' . e($lead->phone),
            $lead->email ? '<b>Email:</b> ' . e($lead->email) : null,
            $lead->message ? '<b>Комментарий:</b> ' . e($lead->message) : null,
            '',
            $lead->page_url ? '<b>Страница:</b> ' . e($lead->page_url) : null,
            // Адрес карточки собирает роутер, а не клиент — здесь e() не нужен.
            '<b>Открыть:</b> ' . route('filament.admin.resources.leads.view', $lead),
        ]));
    }
}
```

`e()` **на каждом** значении, пришедшем от клиента или из БД, обязателен: `parse_mode: HTML`
означает, что имя вида `<b>hack` сломает разметку сообщения, Telegram вернёт 400, и задача
уйдёт в пять ретраев по вине клиента, а не сети. Ошибка не воспроизводится на нормальных
данных и ждёт первого клиента с `<` в имени или комментарии — поэтому её проверяет
отдельный тест.

`sourceLabel()` — тоже пользовательские данные: марка и модель автомобиля, название
услуги приходят из админки.

## Job

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class NotifyManagerAboutLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Растущие паузы: сеть моргнула — доставим через 10с; API лежит — через 15 мин.
     *
     * Четыре значения на пять попыток — не опечатка: Laravel повторяет
     * последнее значение для всех последующих попыток, то есть пятая ждёт
     * те же 900 секунд. Дописывать сюда пятый элемент не нужно.
     */
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
            'phone'   => substr($this->lead->phone, -4), // хвост номера, не весь номер
            'error'   => $e->getMessage(),
        ]);
    }
}
```

`use Queueable` — трейт Laravel 11+ (`Illuminate\Foundation\Queue\Queueable`), заменяющий
связку `Dispatchable, InteractsWithQueue, Queueable, SerializesModels` из Laravel 10.

Job принимает модель, а не массив: `SerializesModels` сохранит в очередь только id и
подтянет свежую запись при выполнении.

## Диспетчеризация

```php
NotifyManagerAboutLead::dispatch($lead)->afterCommit();
```

`afterCommit()` критичен: воркер Redis быстрее коммита транзакции. Без него задача может
взять id лида, которого в БД ещё нет, и упасть на `ModelNotFoundException`.

Альтернатива — глобально в `config/queue.php`:

```php
'redis' => [
    // ...
    'after_commit' => true,
],
```

## Воркер на проде

```bash
php artisan queue:work redis --queue=default --tries=5 --max-time=3600
```

Воркер должен жить под супервизором (systemd/Supervisor/Horizon) с автоперезапуском.
Мёртвый воркер = заявки в БД есть, а менеджер о них не знает — самый неприятный сценарий,
потому что снаружи всё выглядит рабочим.

Обязательно: таблица `failed_jobs` (`php artisan queue:failed-table`) и регулярная проверка
`php artisan queue:failed`. Не доставленный лид не должен исчезать бесследно.

## Проверка вручную

Проверить, что бот сконфигурирован и доступен — `php artisan tinker`:

```php
Http::get('https://api.telegram.org/bot'.config('services.telegram.token').'/getMe')->json();
// Ожидаем ["ok" => true, "result" => [...]]. ["ok" => false] — токен неверный или отозван.
```

```bash
# Разово выполнить одну задачу из очереди
php artisan queue:work --once

# Посмотреть и повторить упавшие
php artisan queue:failed
php artisan queue:retry all
```
