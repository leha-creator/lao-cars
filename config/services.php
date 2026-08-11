<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Telegram-бот, уведомляющий менеджера о новой заявке (веха 3.7).
    |
    | Пустые токен или chat_id — не ошибка: на локальной машине и в CI бота
    | нет, уведомление пропускается с WARN в канал `leads`, заявка при этом
    | сохраняется. Реальные значения живут только в `.env` и в секретах
    | деплоя; в лог токен не попадает никогда, включая текст исключения.
    */
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),

        // Общий чат — ФОЛБЭК с вехи 4.7, а не основной адрес.
        //
        // Уведомления уходят на персональные `users.telegram_chat_id`,
        // и сюда `LeadNotifier` обращается только тогда, когда таких
        // получателей нет ВОВСЕ. Слать и туда, и туда отклонено: на проде
        // это дубль каждой заявки навсегда, начиная с первого заполненного
        // профиля. Убрать совсем отклонено тоже: до того, как сотрудники
        // заполнят профили, уведомления пропали бы молча.
        'chat_id' => env('TELEGRAM_MANAGER_CHAT_ID'),
        // Только таймаут. Повторы принадлежат задаче очереди
        // (`NotifyManagerAboutLead::$tries`), а не HTTP-клиенту: retry
        // здесь вместе с $tries дал бы до пятнадцати обращений к API
        // на один лид.
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 10),
    ],

];
