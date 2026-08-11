<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Приём заявки: запись в БД и постановка уведомлений.
 *
 * Порядок здесь — не деталь реализации, а требование `DESCRIPTION.md`:
 * запись первична, уведомление вторично. Telegram недоступен — заявка
 * всё равно сохранена, и менеджер найдёт её в админке.
 *
 * Вехой 4.7 каналов стало три (Telegram, push, колокольчик панели),
 * а получателей — сколько заведено сотрудников. Знание о том, кому и куда,
 * уехало в `LeadNotifier`; здесь остался один вызов ровно на том же месте,
 * где раньше стоял диспатч. Порядок и `afterCommit()` не тронуты.
 */
final class LeadService
{
    public function __construct(private readonly LeadNotifier $notifier) {}

    public function capture(LeadData $data): Lead
    {
        $lead = DB::transaction(fn (): Lead => Lead::create($data->toAttributes()));

        // Персональные данные клиента в канал `leads` не пишутся —
        // запрет стоит в шапке канала (`config/logging.php`). Здесь
        // только то, по чему заявку находят: id, источник и страница.
        Log::channel('leads')->info('[Lead] заявка принята', [
            'lead_id' => $lead->id,
            'source_type' => $lead->source_type,
            'source_id' => $lead->source_id,
            'page_url' => $lead->page_url,
        ]);

        // `afterCommit()` на задачах внутри — страховка, а не
        // необходимость: `DB::transaction()` выше уже вернул управление,
        // то есть транзакция закрыта и задачи ушли бы немедленно и без
        // него. Смысл — в будущем вызывающем, который обернёт `capture()`
        // в собственную транзакцию (импорт заявок, пакетная операция
        // в админке): без `afterCommit()` воркер Redis успевает забрать
        // задачу раньше коммита и падает на несуществующем лиде —
        // с сохранившимся лидом и потерянным уведомлением.
        //
        // Запись «уведомления поставлены в очередь» делает сам
        // `LeadNotifier`: только он знает число получателей по каждому
        // каналу, а без этого числа запись отвечает на вопрос
        // «отправили?» и не отвечает на вопрос «кому?».
        $this->notifier->notify($lead);

        return $lead;
    }
}
