<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Подписка и отписка браузера сотрудника (веха 4.7).
 *
 * Эндпоинт объявлен в `routes/web.php` обычной группой `auth`, а не внутри
 * панели Filament, и это не небрежность: страницей панели он не является,
 * а спрятанный в провайдере панели роут теряется при первом же поиске
 * по маршрутам — «где регистрируется подписка» перестаёт быть вопросом
 * с ответом.
 *
 * Контроллер тонкий по правилу `ARCHITECTURE.md`: валидация —
 * в `StorePushSubscriptionRequest`, запись — вызовом трейта пакета.
 * Сервиса-прослойки здесь нет намеренно: оркестрировать нечего, это одна
 * запись в одну таблицу — та же граница, что у `PartsController`.
 */
final class PushSubscriptionController extends Controller
{
    /**
     * Создать или обновить подписку текущего браузера.
     *
     * `updatePushSubscription()` из трейта пакета делает ровно то, что
     * нужно, и делает это в одном месте: находит строку по `endpoint`,
     * обновляет её у владельца, а у чужого — удаляет и заводит свою.
     * Последнее принципиально: за одним браузером могут по очереди войти
     * двое, и оставшаяся подписка слала бы заявки не тому человеку.
     */
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $endpoint = (string) $request->input('endpoint');

        $existing = PushSubscription::findByEndpoint($endpoint);
        $isNew = $existing === null;

        $subscription = $user->updatePushSubscription(
            $endpoint,
            (string) $request->input('keys.p256dh'),
            (string) $request->input('keys.auth'),
            $request->input('contentEncoding'),
        );

        // `user_agent` пакет не знает и через трейт не проходит — это
        // наша колонка, и заполняется она отдельно. Заголовок обрезается
        // по длине колонки: строки некоторых мобильных браузеров длиннее
        // 255 символов, и без обрезки подписка падала бы ошибкой драйвера
        // ровно у тех, кому push нужнее всего.
        $subscription->forceFill([
            'user_agent' => Str::substr((string) $request->userAgent(), 0, 255),
        ])->save();

        // DEBUG, а не INFO: событие штатное и частое (перерегистрация
        // происходит при каждом обновлении подписки браузером). Хвост
        // `endpoint`, а не сам адрес: это адрес доставки конкретному
        // человеку, и в файле лога, который уезжает в бэкапы, ему
        // не место. Ключи `p256dh` и `auth` в лог не идут никогда —
        // это материал шифрования.
        Log::channel('leads')->debug(
            $isNew ? '[Push] подписка создана' : '[Push] подписка обновлена',
            [
                'user_id' => $user->getKey(),
                'endpoint_tail' => $subscription->endpointTail(),
                'origin' => 'browser',
            ],
        );

        return response()->json(['status' => $isNew ? 'created' : 'updated'], $isNew ? 201 : 200);
    }

    /**
     * Отозвать подписку текущего браузера.
     *
     * Удаляется только своя строка: `deletePushSubscription()` из трейта
     * фильтрует по отношению, то есть чужой `endpoint` в теле запроса
     * не удалит ничего. Отвечать при этом одинаково — успех и в том,
     * и в другом случае: 404 на чужой адрес сообщил бы, что такая
     * подписка существует.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $endpoint = (string) $validated['endpoint'];

        $user->deletePushSubscription($endpoint);

        Log::channel('leads')->debug('[Push] подписка отозвана', [
            'user_id' => $user->getKey(),
            'endpoint_tail' => Str::substr($endpoint, -12),
            // Отозвать подписку можно из браузера (эта ручка) и из списка
            // устройств в кабинете (задача 16). Событие одно, путей два,
            // и различать их в логе обязательно: «я ничего не отключал»
            // разбирается только так.
            'origin' => 'browser',
        ]);

        return response()->json(['status' => 'deleted']);
    }
}
