<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes\Pages;

use App\Filament\Resources\CarAttributes\CarAttributeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCarAttribute extends CreateRecord
{
    protected static string $resource = CarAttributeResource::class;
}
