<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes\Pages;

use App\Filament\Resources\CarAttributes\CarAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCarAttributes extends ListRecords
{
    protected static string $resource = CarAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
