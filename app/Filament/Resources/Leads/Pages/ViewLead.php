<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\Actions\ChangeLeadStatusAction;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * Те же экземпляры действий, что и в строке списка: они приходят
     * из общего класса, а не собираются здесь заново (правило `RULES.md`).
     *
     * Кнопки редактирования тут нет и не будет: страницы `Edit` у заявки
     * нет намеренно (`LeadResource::getPages()`).
     */
    protected function getHeaderActions(): array
    {
        return [
            ChangeLeadStatusAction::takeInWork(),
            ChangeLeadStatusAction::close(),
            ChangeLeadStatusAction::reopen(),
        ];
    }
}
