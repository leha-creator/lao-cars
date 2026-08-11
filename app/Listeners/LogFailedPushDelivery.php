<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * След недоставленного push-уведомления (веха 4.7).
 *
 * Два разных события с общим кодом ответа приходят сюда вместе,
 * и различать их обязательно.
 *
 * 404 и 410 — «подписка мертва»: браузер переустановлен, разрешение
 * отозвано, данные сайта очищены. Строку в этом случае удаляет сам пакет
 * (`ReportHandler::handleReport()` вызывает `delete()` до того, как
 * событие сюда доедет), поэтому здесь только запись — дублировать
 * удаление нельзя, вторая попытка удалить уже удалённое ничего
 * не сломает, но соврёт в логе о том, кто это сделал.
 *
 * Всё остальное — настоящий сбой доставки: недоступный push-сервис,
 * неверная подпись VAPID, слишком большая полезная нагрузка.
 */
final class LogFailedPushDelivery
{
    public function handle(NotificationFailed $event): void
    {
        $report = $event->report;
        $subscription = $event->subscription;

        $context = [
            'user_id' => $subscription->subscribable_id,
            'endpoint_tail' => $subscription instanceof PushSubscription
                ? $subscription->endpointTail()
                : mb_substr($report->getEndpoint(), -12),
            'status' => $report->getResponse()?->getStatusCode(),
        ];

        if ($report->isSubscriptionExpired()) {
            // INFO, а не ERROR: это не отказ, а штатная уборка. Без записи
            // список устройств в кабинете однажды укорачивается сам,
            // и объяснить это будет нечем.
            Log::channel('leads')->info('[Push] протухшая подписка удалена', $context);

            return;
        }

        // ERROR: доставка не удалась, и повторять её некому — канал
        // `webpush` отправляет партией и своих ретраев не имеет.
        // Причина в контексте обязательна: «не дошло» без неё
        // не отличается от «не дошло по другой причине».
        Log::channel('leads')->error('[Push] уведомление не доставлено', $context + [
            'reason' => $report->getReason(),
        ]);
    }
}
