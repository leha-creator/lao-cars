<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars\Pages;

use App\Filament\Actions\HelpAction;
use App\Filament\Resources\Cars\CarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCars extends ListRecords
{
    protected static string $resource = CarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            HelpAction::make('car-publishing'),
        ];
    }
}
