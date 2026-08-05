<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    /**
     * Кнопки создания в шапке нет: заявки создаёт сайт
     * (`LeadResource::canCreate()`).
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Новые — первой вкладкой и по умолчанию: это главный экран раздела,
     * а не список всех заявок за всё время (по образцу `ListReviews`).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'new' => Tab::make('Новые')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', LeadStatus::New))
                ->badge(Lead::new()->count()),

            'in_progress' => Tab::make('В работе')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', LeadStatus::InProgress))
                ->badge(Lead::query()->where('status', LeadStatus::InProgress)->count()),

            'all' => Tab::make('Все')
                ->badge(Lead::query()->count()),
        ];
    }
}
