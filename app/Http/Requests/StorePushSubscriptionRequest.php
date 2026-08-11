<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Minishlink\WebPush\ContentEncoding;

/**
 * Подписка браузера на push-уведомления (веха 4.7).
 *
 * Тело запроса — это результат `PushSubscription.toJSON()` из браузера,
 * то есть форма его задана стандартом Push API, а не нами. Отсюда
 * вложенность `keys.p256dh` / `keys.auth`: переименовывать поля на своём
 * конце значило бы разбирать объект в скрипте, а разбор на клиенте
 * разъезжается со стандартом молча.
 */
final class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * Доступ ограничен middleware `auth` на роуте: подписка привязывается
     * к текущему пользователю, и анонимному её привязать не к чему.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 500 — длина колонки. Правило мягче колонки означало бы
            // ошибку драйвера PostgreSQL вместо ответа с ошибкой,
            // то есть 500 у сотрудника вместо «подписка не сохранена».
            'endpoint' => ['required', 'string', 'url', 'max:500'],

            // Ключи шифрования полезной нагрузки. Без любого из них
            // сообщение зашифровать нечем, и подписка бесполезна —
            // поэтому оба обязательны, а не nullable.
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],

            // Кодировка приходит не от всех браузеров: старые Firefox
            // её не сообщают, и пакет подставляет умолчание сам.
            //
            // Правило именно по enum-у, а не `string|max:32`: значение
            // уходит в `ContentEncoding::from()` внутри трейта пакета,
            // а тот на незнакомой строке бросает `ValueError`. Правило
            // мягче потребителя означает 500 вместо сообщения об ошибке
            // — то же самое, что правило мягче колонки у `endpoint` выше,
            // только падает не драйвер БД, а enum.
            'contentEncoding' => ['nullable', Rule::enum(ContentEncoding::class)],
        ];
    }
}
