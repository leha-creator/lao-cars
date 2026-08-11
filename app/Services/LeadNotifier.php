<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\NotifyManagerAboutLead;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Кому и по каким каналам уходит новая заявка (веха 4.7).
 *
 * ЕДИНСТВЕННОЕ место в проекте, знающее состав получателей. До вехи 4.7
 * его не было вовсе: `LeadService` диспатчил одну задачу на один
 * `chat_id` из окружения, и адресация умещалась в строку конфига.
 *
 * Сервис без состояния и без `Request` — правило `ARCHITECTURE.md`.
 * Логика выборки получателей живёт не здесь, а в скоупах `User`
 * (`receivingTelegram`, `receivingPush`) по тому же правилу.
 */
final class LeadNotifier
{
    public function notify(Lead $lead): void
    {
        $pushRecipients = User::receivingPush()->get();
        $telegramRecipients = User::receivingTelegram()->get();

        // Фреймворк сам разложит это на задачу для каждого получателя
        // и каждого канала. Цикл с ручным диспатчем здесь был бы той же
        // болезнью, что и цикл внутри одной задачи: упавший пятый
        // получатель утащил бы в ретрай первых четырёх.
        if ($pushRecipients->isNotEmpty()) {
            Notification::send($pushRecipients, new NewLeadNotification($lead));
        }

        $fallback = $this->dispatchTelegram($lead, $telegramRecipients->all());

        Log::channel('leads')->info('[Lead] уведомления поставлены в очередь', [
            'lead_id' => $lead->id,
            'push_recipients' => $pushRecipients->count(),
            'telegram_recipients' => $telegramRecipients->count(),
            // Переход с общего чата на персональные адреса обязан быть
            // видимым событием: пока флаг стоит, ни один сотрудник ещё
            // не заполнил свой профиль, и уведомления идут в общий чат.
            // День, когда он погаснет, — это день, когда общий чат
            // замолчал, и объяснить это иначе будет нечем.
            'fallback' => $fallback,
        ]);

        if ($pushRecipients->isEmpty() && $telegramRecipients->isEmpty() && ! $fallback) {
            // Заявка сохранена, но о ней никто не узнает — ровно тот
            // отказ, ради диагностики которого канал `leads` и заведён.
            // Снаружи сайт при этом выглядит работающим.
            Log::channel('leads')->warning('[Lead] получателей уведомлений нет ни по одному каналу', [
                'lead_id' => $lead->id,
            ]);
        }
    }

    /**
     * Задачи Telegram — по одной на получателя.
     *
     * Именно по одной, а не цикл внутри одной задачи: упавшая отправка
     * пятому увела бы в ретрай всю задачу, и первые четверо получили бы
     * заявку повторно. Причём тем чаще, чем хуже работает связь.
     *
     * @param  list<User>  $recipients
     * @return bool Сработал ли фолбэк на общий чат из окружения.
     */
    private function dispatchTelegram(Lead $lead, array $recipients): bool
    {
        if ($recipients !== []) {
            foreach ($recipients as $recipient) {
                NotifyManagerAboutLead::dispatch($lead, (string) $recipient->telegram_chat_id)->afterCommit();
            }

            return false;
        }

        // Общий `chat_id` из окружения — фолбэк, и только фолбэк.
        //
        // «Слать и туда, и в персональные» отклонено: на проде это дубль
        // каждой заявки навсегда, начиная с первого заполненного профиля.
        // «Убрать общий чат совсем» отклонено тоже: до того, как сотрудники
        // заполнят профили, уведомления пропали бы молча — тот самый
        // самый дорогой отказ, который веха 3.7 описала дословно.
        $shared = (string) config('services.telegram.chat_id');

        if ($shared === '') {
            return false;
        }

        NotifyManagerAboutLead::dispatch($lead, $shared)->afterCommit();

        return true;
    }
}
