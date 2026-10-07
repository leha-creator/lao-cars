<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Actions;

use App\Models\Lead;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Обезличивание заявок, срок хранения которых истёк.
 *
 * Закрытая заявка хранится `config('leads.retention_years')` лет и затем
 * теряет всё, что указывает на человека, — так записано в разделе 9
 * политики обработки персональных данных. Расписания у этого нет намеренно:
 * действие необратимо, и запускает его человек, который видит отбор
 * (вкладка «Срок хранения истёк»), а не крон в три часа ночи.
 *
 * **Срок проверяет действие, а не выделение в таблице.** Отметить строки
 * можно на любой вкладке, поэтому каждая запись сверяется с
 * `Lead::isRetentionExpired()` заново: заявка в работе или закрытая месяц
 * назад пропускается, даже если её отметили вручную. Вкладка показывает,
 * что будет затронуто, но не решает этого.
 *
 * Право — метод `anonymize` политики заявок (только администратор), а не
 * проверка роли по месту: матрица прав проекта живёт в политиках.
 * Невидимое действие Filament не даёт и вызвать, так что скрытие здесь —
 * запрет, а не оформление.
 */
final class AnonymizeLeadsBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('anonymize')
            ->label('Обезличить')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->can('anonymize', Lead::class) === true)
            ->requiresConfirmation()
            ->modalHeading('Обезличить отмеченные заявки?')
            ->modalDescription('У заявок будут стёрты имя, телефон, почта, текст обращения и VIN. Вернуть их нельзя. Заявки, срок хранения которых не истёк, действие пропустит.')
            ->modalSubmitActionLabel('Обезличить')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $anonymized = 0;
                $skipped = 0;

                /** @var Lead $lead */
                foreach ($records as $lead) {
                    if (! $lead->isRetentionExpired()) {
                        $skipped++;

                        continue;
                    }

                    $closedAt = $lead->closed_at;
                    $lead->anonymize();
                    $anonymized++;

                    // Запись на каждую заявку — основание для акта об
                    // уничтожении. Имени и телефона в ней нет: запрет
                    // шапки канала `leads`, и к этому моменту их уже нет
                    // и в базе.
                    Log::channel('leads')->info('[Lead] заявка обезличена', [
                        'lead_id' => $lead->getKey(),
                        'closed_at' => $closedAt?->toIso8601String(),
                        'actor_id' => auth()->id(),
                    ]);
                }

                Log::channel('leads')->info('[Lead] обезличивание завершено', [
                    'anonymized' => $anonymized,
                    'skipped' => $skipped,
                    'actor_id' => auth()->id(),
                ]);

                Notification::make()
                    ->title("Обезличено заявок: {$anonymized}")
                    ->body($skipped > 0 ? "Пропущено: {$skipped} — срок хранения не истёк или заявка уже обезличена." : null)
                    ->success()
                    ->send();
            });
    }
}
