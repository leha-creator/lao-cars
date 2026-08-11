<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Уведомление сотрудника о новой заявке (веха 4.7).
 *
 * Два канала — колокольчик в панели (`database`) и push в браузере
 * (`webpush`), — и это НЕ расширение задачи `NotifyManagerAboutLead`,
 * а отдельный путь доставки. Один job на несколько каналов при падении
 * одного из них ушёл бы в ретрай целиком и переслал бы остальные повторно:
 * менеджер получал бы дубли тем чаще, чем хуже работает самый ненадёжный
 * канал. Механизм Laravel Notifications создаёт отдельную задачу
 * на каждого получателя и каждый канал, поэтому ретрай бьёт точечно.
 *
 * Telegram сюда НЕ переезжает намеренно: у него собственная задача
 * с настроенными `$tries`/`$backoff`, форматом сообщения и экранированием
 * вехи 3.7 — инвариантами, которые перенос в канал уведомления переписал
 * бы целиком ради единообразия и ничего не улучшил.
 */
final class NewLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Колокольчик работает всегда: он не зависит ни от ключей VAPID,
        // ни от разрешения браузера.
        //
        // Флаг `notify_push` получателя здесь НЕ проверяется намеренно.
        // Кому уходит заявка, знает `LeadNotifier` и только он — это
        // единственное место адресации. Вторая проверка здесь была бы
        // вторым источником истины, и разошлись бы они молча: скоуп
        // выборки и условие канала правят в разное время и по разным
        // поводам.
        $channels = ['database'];

        if ($this->vapidConfigured()) {
            $channels[] = WebPushChannel::class;

            return $channels;
        }

        // Незаполненные ключи — WARN и пропуск канала, а не исключение.
        // Ровно как несконфигурированный Telegram вехи 3.7: на локальной
        // машине и в CI ключей нет, и заваливать `failed_jobs` пятью
        // попытками на каждый лид незачем.
        //
        // Запись одна на уведомление, а не на получателя: при пяти
        // сотрудниках в логе было бы пять одинаковых строк об одном
        // и том же незаполненном конфиге.
        Log::channel('leads')->warning('[Push] ключи VAPID не заданы, push пропущены', [
            'lead_id' => $this->lead->id,
        ]);

        return $channels;
    }

    /**
     * Содержимое колокольчика в панели.
     *
     * Здесь персональные данные допустимы, в отличие от push: панель
     * закрыта авторизацией, и её видит только тот, кому заявка адресована.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'title' => 'Новая заявка',
            'source' => $this->lead->sourceLabel(),
            'name' => $this->lead->name,
            'phone' => $this->lead->phone,
            'url' => $this->leadUrl(),
        ];
    }

    /**
     * Содержимое push-уведомления.
     *
     * ТЕЛЕФОНА И КОММЕНТАРИЯ КЛИЕНТА ЗДЕСЬ НЕТ, и это не забывчивость.
     * Push рисуется системой поверх экрана блокировки — то есть на экране,
     * который видит не только адресат: телефон приходит на разбуженный
     * телефон, лежащий на столе в мастерской. «Добавить телефон, чтобы
     * менеджер сразу перезванивал» выглядит очевидным улучшением и
     * стоит ровно того, ради чего в проекте заведён запрет на персональные
     * данные в канале `leads`: их нельзя вычистить оттуда, куда они
     * однажды попали.
     *
     * Всё остальное открывается по клику — в карточке заявки, за
     * авторизацией. Telegram при этом остаётся полным: там приватный чат,
     * и решение вехи 3.7 не пересматривается.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Новая заявка')
            ->body($this->lead->sourceLabel().' · '.$this->lead->name)
            ->icon('/notification-icon.png')
            // Тег по id заявки обязателен: push-сервисы доставляют
            // сообщение повторно при неподтверждённой доставке, и без
            // тега одна заявка даёт два всплывающих окна — менеджер
            // решает, что заявок две, и звонит дважды.
            ->tag('lead-'.$this->lead->id)
            ->data(['url' => $this->leadUrl()]);
    }

    /**
     * Ключи VAPID заполнены — можно ли вообще подписывать сообщения.
     *
     * Проверяются оба: пакет при пустом любом из них молча отправляет
     * запрос без подписи, а push-сервис отвечает 401 — то есть отказ
     * приезжает от чужого сервиса и выглядит как сбой сети.
     */
    private function vapidConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    private function leadUrl(): string
    {
        return route('filament.admin.resources.leads.view', $this->lead);
    }
}
