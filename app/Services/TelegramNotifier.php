<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Отправка уведомления о заявке менеджеру в Telegram (веха 3.7).
 *
 * Вызывается только из очереди (`NotifyManagerAboutLead`): синхронный
 * вызов внешнего API в HTTP-цикле — антипаттерн из `ARCHITECTURE.md`,
 * время ответа формы становится заложником чужого сервиса.
 *
 * Два вида отказа различаются намеренно:
 *   - несконфигурированный бот (пустой токен или chat_id) — WARN и выход
 *     без исключения: на локальной машине и в CI бота нет, и заваливать
 *     `failed_jobs` пятью попытками на каждый тестовый лид незачем;
 *   - недоступный API — исключение: это временный сбой, ради которого
 *     ретраи и существуют.
 */
final class TelegramNotifier
{
    public function send(Lead $lead): void
    {
        // Через config(), а не env(): вызов env() вне config-файлов после
        // `php artisan config:cache` вернёт null (ARCHITECTURE.md).
        $token = (string) config('services.telegram.token');
        $chatId = (string) config('services.telegram.chat_id');

        if ($token === '' || $chatId === '') {
            Log::channel('leads')->warning('[Lead] Telegram не сконфигурирован, уведомление пропущено', [
                'lead_id' => $lead->id,
                // Что именно не заполнено — видно, а значения не пишутся.
                'missing' => $token === '' ? 'token' : 'chat_id',
            ]);

            return;
        }

        // `->retry()` здесь нет намеренно: повторы принадлежат задаче
        // ($tries = 5 и $backoff). Retry у клиента вместе с ретраями
        // задачи дал бы до пятнадцати обращений к API на один лид
        // и растянул бы «мгновенное уведомление» на минуты внутри
        // одной попытки.
        $response = Http::timeout((int) config('services.telegram.timeout'))
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->format($lead),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if ($response->failed()) {
            // В исключение идут только статус и тело ответа. URL запроса
            // содержит токен, и `$e->getMessage()` унёс бы его в лог —
            // файл, который живёт 30 дней и попадает в бэкапы.
            throw new RuntimeException(sprintf(
                'Telegram отклонил уведомление о заявке #%d: HTTP %d, %s',
                $lead->id,
                $response->status(),
                $response->body(),
            ));
        }

        Log::channel('leads')->info('[Lead] уведомление доставлено в Telegram', [
            'lead_id' => $lead->id,
        ]);
    }

    /**
     * Текст уведомления.
     *
     * `e()` на каждом пользовательском значении обязателен: при
     * `parse_mode: HTML` имя вида `<b` ломает разметку, Telegram отвечает
     * 400, и задача уходит в пять ретраев по вине клиента, а не сети.
     * Ошибка не воспроизводится на нормальных данных и ждёт первого
     * клиента с `<` в имени или комментарии.
     */
    private function format(Lead $lead): string
    {
        $lines = [
            '<b>Новая заявка</b>',
            '',
            'Источник: '.e($lead->sourceLabel()),
            'Имя: '.e((string) $lead->name),
            'Телефон: '.e((string) $lead->phone),
        ];

        if (filled($lead->email)) {
            $lines[] = 'E-mail: '.e((string) $lead->email);
        }

        if ($lead->contact_method !== null) {
            $lines[] = 'Способ связи: '.e($lead->contact_method->label());
        }

        if ($lead->preferred_time !== null) {
            $lines[] = 'Удобное время: '.e($lead->preferred_time->label());
        }

        if (filled($lead->message)) {
            $lines[] = 'Комментарий: '.e((string) $lead->message);
        }

        if ($lead->isPartsRequest()) {
            $lines[] = '';
            $lines[] = '<b>Подбор запчасти</b>';

            foreach (['Марка' => $lead->part_brand, 'Модель' => $lead->part_model, 'VIN' => $lead->part_vin] as $label => $value) {
                if (filled($value)) {
                    $lines[] = $label.': '.e((string) $value);
                }
            }
        }

        if (filled($lead->page_url)) {
            $lines[] = '';
            $lines[] = 'Страница: '.e((string) $lead->page_url);
        }

        $lines[] = '';
        // Ссылка на карточку заявки: менеджер попадает в панель одним
        // касанием, а не ищет заявку в списке. `e()` не нужен — адрес
        // собирает роутер, а не клиент.
        $lines[] = 'Открыть в админке: '.route('filament.admin.resources.leads.view', $lead);

        return implode("\n", $lines);
    }
}
