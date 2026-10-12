<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AnalyticsPeriod;
use App\Filament\Widgets\OpsStatusWidget;
use App\Filament\Widgets\RequestsChartWidget;
use App\Filament\Widgets\ServerStatsWidget;
use App\Filament\Widgets\TopCarsWidget;
use App\Filament\Widgets\TopPagesWidget;
use App\Filament\Widgets\TrafficSourcesWidget;
use App\Filament\Widgets\TrafficStatsWidget;
use App\Filament\Widgets\VisitsChartWidget;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Widgets\AccountWidget;

/**
 * Главная страница панели — инфопанель.
 *
 * Менеджер видит здесь только карточку учётной записи. Администратор —
 * ещё и состояние служб, сервера, счётчик запросов и статистику посещений:
 * каждый из восьми виджетов закрыт трейтом `AdminOnly`.
 *
 * Страница наследует штатную и собирается без своего Blade — по тому же
 * основанию, что `ManageSiteSettings`: шаблон страницы Filament не входит
 * в его публичный контракт и меняется между минорными версиями.
 *
 * ФИЛЬТР ПЕРИОДА ОДИН НА СТРАНИЦУ, а не по фильтру в каждом виджете:
 * запросы за час рядом с посещениями за неделю — два числа, которые
 * нельзя сравнить.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Инфопанель';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Период')
                // Строками, а не классом перечисления: состояние фильтра
                // живёт в публичном свойстве Livewire, в адресной строке
                // и в сессии — объект перечисления ни одно из трёх не хранит.
                ->options(AnalyticsPeriod::options())
                ->default(AnalyticsPeriod::Today->value)
                ->selectablePlaceholder(false),
        ]);
    }

    /**
     * Менеджеру форма не показывается: фильтровать ему нечего.
     */
    public function getFiltersFormContentComponent(): Component
    {
        return parent::getFiltersFormContentComponent()
            ->visible(fn (): bool => auth()->user()?->isAdmin() === true);
    }

    /**
     * Порядок блоков задан здесь списком, а не `$sort` в каждом виджете:
     * сверху то, что требует действий (службы, сервер), ниже — то, что
     * читают (запросы, посещаемость, таблицы).
     *
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            OpsStatusWidget::class,
            ServerStatsWidget::class,
            RequestsChartWidget::class,
            TrafficStatsWidget::class,
            VisitsChartWidget::class,
            TopPagesWidget::class,
            TrafficSourcesWidget::class,
            TopCarsWidget::class,
        ];
    }
}
