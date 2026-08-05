<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\Leads\Schemas\LeadInfolist;
use App\Filament\Resources\Leads\Tables\LeadsTable;
use App\Models\Car;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use UnitEnum;

/**
 * Единый список заявок со всех форм сайта (раздел 4 ТЗ).
 *
 * Раздел — вторая половина работы менеджера: политика `LeadPolicy` была
 * написана вехой 3.5 до появления этого ресурса, а группа меню
 * `NavigationGroup::Leads` заведена вехой 3.4 именно под него.
 */
final class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Leads;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Заявка';

    protected static ?string $pluralModelLabel = 'Заявки';

    /**
     * Заявки создаёт сайт, а не админка: форма в панели завела бы второй
     * путь появления лида мимо валидации, honeypot и уведомления.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Источник — полиморфная связь, и без предзагрузки колонка источника
     * даёт по запросу на каждую строку списка (антипаттерн N+1
     * из `ARCHITECTURE.md`). Для `morphTo` `with()` подтягивает записи
     * пачкой на каждый тип источника, а не на каждую строку.
     *
     * `morphWith()` обязателен и отдельно от `with('source')`:
     * `Lead::sourceLabel()` собирает подпись автомобиля из марки
     * (`$source->brand?->name`), а голая предзагрузка источника марку
     * не тянет — и список из двадцати заявок на двадцать разных
     * автомобилей даёт двадцать запросов к `brands` поверх остальных.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Car::class => ['brand']]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
        ];
    }

    /**
     * Страницы `Create` и `Edit` не объявлены намеренно.
     *
     * Содержимое заявки — слова клиента, и правка телефона «по памяти
     * менеджера» уничтожает единственный след того, что клиент на самом
     * деле ввёл. Работа с заявкой — это статус и комментарии, оба живут
     * на странице просмотра.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'view' => ViewLead::route('/{record}'),
        ];
    }

    /**
     * Счётчик новых заявок в меню: раздел, где что-то ждёт обработки,
     * должен быть виден без захода в него (по образцу `ReviewResource`).
     */
    public static function getNavigationBadge(): ?string
    {
        $new = Lead::new()->count();

        return $new > 0 ? (string) $new : null;
    }
}
