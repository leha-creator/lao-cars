<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Models\Car;
use App\Models\Lead;
use App\Models\Service;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Карточка заявки — только чтение.
 *
 * Формы редактирования у заявки нет намеренно (`LeadResource::getPages()`):
 * содержимое заявки — слова клиента. Работа с ней идёт статусом
 * и комментариями.
 */
final class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->schema([
                    TextEntry::make('status')
                        ->label('Статус')
                        ->badge(),

                    TextEntry::make('created_at')
                        ->label('Получена')
                        ->dateTime('d.m.Y H:i'),

                    TextEntry::make('source')
                        ->label('Источник')
                        ->state(fn (Lead $record): string => $record->sourceLabel())
                        // Ссылка ведёт на публичную страницу источника,
                        // а не на его карточку в админке: менеджер по
                        // телефону смотрит ровно то, что видит клиент.
                        ->url(fn (Lead $record): ?string => self::sourceUrl($record))
                        ->openUrlInNewTab(),

                    TextEntry::make('page_url')
                        ->label('Страница отправки')
                        ->placeholder('—')
                        ->url(fn (Lead $record): ?string => $record->page_url)
                        ->openUrlInNewTab(),
                ])
                ->columns(2),

            Section::make('Контакт')
                ->schema([
                    TextEntry::make('name')->label('Имя'),

                    TextEntry::make('phone')
                        ->label('Телефон')
                        ->copyable(),

                    TextEntry::make('email')
                        ->label('E-mail')
                        ->placeholder('—')
                        ->copyable(),

                    TextEntry::make('contact_method')
                        ->label('Способ связи')
                        ->placeholder('Не важно'),

                    TextEntry::make('preferred_time')
                        ->label('Удобное время')
                        ->placeholder('Не важно'),

                    TextEntry::make('message')
                        ->label('Комментарий клиента')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            // Секция видна только у заявок на подбор: у остальных все три
            // поля пустые, и пустая секция на каждой карточке — шум.
            Section::make('Подбор запчасти')
                ->schema([
                    TextEntry::make('part_brand')->label('Марка')->placeholder('—'),
                    TextEntry::make('part_model')->label('Модель')->placeholder('—'),
                    TextEntry::make('part_vin')->label('VIN')->placeholder('—')->copyable(),
                ])
                ->columns(3)
                ->visible(fn (Lead $record): bool => $record->isPartsRequest()),
        ]);
    }

    /**
     * Публичный адрес источника заявки.
     *
     * `null` и для общей формы, и для удалённого источника: `nullableMorphs`
     * внешнего ключа не создаёт, поэтому `source_id` может указывать
     * в никуда (риск зафиксирован в плане вехи 3.7).
     */
    private static function sourceUrl(Lead $lead): ?string
    {
        $source = $lead->source;

        return match (true) {
            $source instanceof Car => route('catalog.show', $source),
            // У страницы услуги собственного адреса пока нет — она придёт
            // вехой 4.4 одной страницей на все услуги.
            $source instanceof Service => null,
            default => null,
        };
    }
}
