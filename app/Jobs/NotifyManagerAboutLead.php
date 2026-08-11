<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Уведомление менеджера о новой заявке (веха 3.7).
 *
 * Задача существует ради одного инварианта: заявка первична, уведомление
 * вторично. Telegram лежит — лид уже в БД, а задача уходит в ретраи.
 *
 * Вехой 4.7 адрес получателя стал ПАРАМЕТРОМ задачи, а не значением
 * из конфига. Одна задача на одного получателя: цикл по списку внутри
 * задачи упал бы на пятом и увёл бы в ретрай первых четырёх, то есть
 * четверо получили бы заявку повторно — и тем чаще, чем хуже связь.
 * Кого именно уведомлять, решает `LeadNotifier`; сюда адрес приходит
 * уже непустым.
 */
final class NotifyManagerAboutLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Паузы между попытками, в секундах.
     *
     * В массиве четыре значения на пять попыток намеренно: Laravel
     * повторяет последнее значение для всех последующих попыток, то есть
     * пятая ждёт те же 900 секунд. Дописывать сюда пятый элемент не нужно.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300, 900];

    /**
     * Конструктор принимает модель, а не массив: `SerializesModels` кладёт
     * в очередь только id и подтягивает свежую запись при выполнении —
     * уведомление уйдёт с актуальными данными, а не с копией, снятой
     * в момент отправки формы.
     *
     * `$chatId` — строка, а не модель `User`: адресом может оказаться
     * общий чат из окружения, у которого никакого пользователя нет.
     */
    public function __construct(public Lead $lead, public string $chatId) {}

    public function handle(TelegramNotifier $telegram): void
    {
        $telegram->send($this->lead, $this->chatId);
    }

    /**
     * Финальный провал после исчерпания ретраев — событие из списка
     * в шапке канала `leads`.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('leads')->error('[Lead] уведомление менеджеру не доставлено', [
            'lead_id' => $this->lead->id,
            // Только последние четыре цифры: персональные данные клиента
            // в лог не идут (запрет в шапке канала), но менеджеру нужно
            // опознать заявку, о которой он не узнал.
            'phone_tail' => mb_substr((string) $this->lead->phone, -4),
            // Получателей теперь несколько, и без адреса непонятно, кому
            // именно не дошло: одна и та же заявка может уйти пятерым,
            // и упасть — только у одного. Хвост, а не полный id: адрес
            // чата — это персональные данные сотрудника.
            'chat_id_tail' => mb_substr($this->chatId, -4),
            'error' => $e->getMessage(),
        ]);
    }
}
