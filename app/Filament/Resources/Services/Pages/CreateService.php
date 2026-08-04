<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;
}
