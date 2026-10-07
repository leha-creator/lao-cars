<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatus;
use App\Filament\Actions\HelpAction;
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
        return [
            HelpAction::make('lead-processing'),
        ];
    }

    /**
     * Новые — первой вкладкой и по умолчанию: это главный экран раздела,
     * а не список всех заявок за всё время (по образцу `ListReviews`).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'new' => Tab::make('Новые')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', LeadStatus::New))
                ->badge(Lead::new()->count()),

            'in_progress' => Tab::make('В работе')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', LeadStatus::InProgress))
                ->badge(Lead::query()->where('status', LeadStatus::InProgress)->count()),

            'all' => Tab::make('Все')
                ->badge(Lead::query()->count()),
        ];

        // Отбор для ежегодного обезличивания — вкладкой, а не фильтром
        // таблицы. Фильтр складывался бы с открытой вкладкой, а раздел
        // открывается на «Новых»: закрытых заявок там нет, и отбор молча
        // показывал бы пустой список тому, кто пришёл его чистить.
        //
        // Условие живёт в модели (`Lead::retentionExpired()`), и то же
        // самое заново проверяет действие: вкладка показывает, что будет
        // затронуто, но не решает этого. Видна только тому, кому доступно
        // действие, — менеджеру она ничего не даёт. Счётчик стоит и при
        // нуле: «0» здесь ответ на вопрос «пора ли», а не шум.
        if (auth()->user()?->can('anonymize', Lead::class) === true) {
            $tabs['retention_expired'] = Tab::make('Срок хранения истёк')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->retentionExpired())
                ->badge(Lead::query()->retentionExpired()->count())
                ->badgeColor('danger');
        }

        return $tabs;
    }
}
