<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceCategories\Pages;

use App\Filament\Actions\HelpAction;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListServiceCategories extends ListRecords
{
    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // Та же статья, что у позиций прайса: категория и позиция —
            // две половины одного экрана заказчика, и разводить их по двум
            // статьям значит заставить читать обе.
            HelpAction::make('price-list'),
        ];
    }
}
