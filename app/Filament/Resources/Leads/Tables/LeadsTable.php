<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\Actions\ChangeLeadStatusAction;
use App\Models\Lead;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Получена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    // Менеджер звонит по этому номеру: выделять его мышью
                    // из таблицы — лишний шаг там, где их и так много.
                    ->copyable(),

                // Через `state()`, а не `make('source.title')`: источники
                // разнотипные, и человекочитаемая подпись живёт в модели
                // (`Lead::sourceLabel()`) — там же, где её берёт текст
                // Telegram-уведомления.
                TextColumn::make('source')
                    ->label('Источник')
                    ->state(fn (Lead $record): string => $record->sourceLabel()),

                // Подпись и цвет придут из `LeadStatus` сами: enum
                // реализует `HasLabel` и `HasColor`.
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),

                IconColumn::make('parts')
                    ->label('Запчасти')
                    ->state(fn (Lead $record): bool => $record->isPartsRequest())
                    ->boolean(),
            ])
            // Свежие заявки сверху: раздел открывают ради новых, а не ради
            // истории.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(LeadStatus::class),

                SelectFilter::make('source_type')
                    ->label('Тип источника')
                    // Значения — алиасы morph map: ровно то, что лежит
                    // в колонке. «Общая форма» — это NULL, поэтому фильтр
                    // собирает запрос сам, а не сравнивает со строкой.
                    ->options([
                        'car' => 'Автомобиль',
                        'service' => 'Услуга',
                        'none' => 'Общая форма',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'car', 'service' => $query->where('source_type', $data['value']),
                        'none' => $query->whereNull('source_type'),
                        default => $query,
                    }),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Получена с'),
                        DatePicker::make('until')->label('Получена по'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                // Те же экземпляры, что и в шапке `ViewLead`: триаж идёт
                // из списка, разбор — со страницы заявки.
                ChangeLeadStatusAction::takeInWork(),
                ChangeLeadStatusAction::close(),
                ChangeLeadStatusAction::reopen(),
                ViewAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ]);
    }
}
