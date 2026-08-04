<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;
}
