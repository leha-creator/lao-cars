<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Actions;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;

/**
 * Смена статуса заявки — действие, а не `SelectColumn`.
 *
 * Формально статус заявки — внутреннее состояние, обе роли имеют на него
 * право, и риска раздачи прав здесь нет (в отличие от публикации отзыва,
 * см. `PublishReviewAction`). Причина другая: смена статуса — событие
 * с последствиями, закрытая заявка перестаёт быть в работе, и это должно
 * попадать в канал `leads` с автором. `SelectColumn` не логирует ничего.
 *
 * Отдельный класс, потому что действия нужны и в строке списка, и в шапке
 * страницы просмотра (правило `RULES.md`): голый экземпляр во втором месте
 * однажды разойдётся с первым.
 *
 * Подтверждения намеренно нет: это триаж на десятки заявок в день,
 * а не публикация на сайт.
 */
final class ChangeLeadStatusAction
{
    public static function takeInWork(): Action
    {
        return Action::make('takeInWork')
            ->label('В работу')
            ->icon(Heroicon::OutlinedPlay)
            ->color('info')
            ->visible(fn (Lead $record): bool => $record->status === LeadStatus::New)
            ->action(fn (Lead $record) => self::apply($record, LeadStatus::InProgress, 'Заявка взята в работу'));
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label('Закрыть')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Lead $record): bool => $record->status !== LeadStatus::Closed)
            ->action(fn (Lead $record) => self::apply($record, LeadStatus::Closed, 'Заявка закрыта'));
    }

    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Вернуть в работу')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Lead $record): bool => $record->status === LeadStatus::Closed)
            ->action(fn (Lead $record) => self::apply($record, LeadStatus::InProgress, 'Заявка возвращена в работу'));
    }

    /**
     * Событие с последствиями — пишется в канал `leads` с автором,
     * в отличие от отказов политик, которые в проекте намеренно
     * не логируются. Персональные данные клиента в запись не идут.
     */
    private static function apply(Lead $lead, LeadStatus $to, string $notification): void
    {
        $from = $lead->status;

        $lead->status = $to;
        $lead->save();

        Log::channel('leads')->info('[Lead] статус изменён', [
            'lead_id' => $lead->getKey(),
            'from' => $from->value,
            'to' => $to->value,
            'actor_id' => auth()->id(),
        ]);

        Notification::make()
            ->title($notification)
            ->success()
            ->send();
    }
}
