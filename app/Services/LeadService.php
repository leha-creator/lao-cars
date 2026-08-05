<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Приём заявки: запись в БД и постановка уведомления.
 *
 * Порядок здесь — не деталь реализации, а требование `DESCRIPTION.md`:
 * запись первична, уведомление вторично. Telegram недоступен — заявка
 * всё равно сохранена, и менеджер найдёт её в админке.
 */
final class LeadService
{
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

        return $lead;
    }
}
