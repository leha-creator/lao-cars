<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationSent;

/**
 * След доставленного push-уведомления (веха 4.7).
 *
 * Единственное место, где видно, что уведомление действительно дошло:
 * доставка происходит в очереди и следов в интерфейсе не оставляет,
 * а жалоба «мне ничего не приходит» без этой записи неотличима
 * от «приходит, но я не замечаю».
 *
 * Слушатель находится автодискавери Laravel по типу аргумента `handle`
 * — отдельной регистрации в провайдере не требуется.
 */
final class LogPushDelivery
{
    public function handle(NotificationSent $event): void
    {
        $subscription = $event->subscription;

        // Отметка «последний раз уведомление уходило тогда-то» —
        // ответ на вопрос «мне точно приходит?» в списке устройств
        // кабинета. `updateQuietly()`, а не `update()`: событие модели
        // здесь не нужно, а `updated_at` у подписки означает изменение
        // самой подписки, а не факт её использования.
        if ($subscription instanceof PushSubscription) {
            $subscription->updateQuietly(['last_used_at' => now()]);
        }

        // INFO, а не DEBUG: это положительная половина пары, вторую
        // половину которой пишет `LogFailedPushDelivery`. Обе нужны,
        // чтобы отличить «не дошло» от «не отправляли».
        //
        // Персональные данные клиента сюда не идут — запрет стоит
        // в шапке канала. Полный `endpoint` тоже: это адрес доставки
        // конкретному человеку.
        Log::channel('leads')->info('[Push] уведомление доставлено', [
            'user_id' => $subscription->subscribable_id,
            'endpoint_tail' => $this->tail($subscription),
        ]);
    }

    private function tail(object $subscription): string
    {
        return $subscription instanceof PushSubscription
            ? $subscription->endpointTail()
            : mb_substr((string) $subscription->endpoint, -12);
    }
}
