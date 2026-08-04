<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Изображение останется в медиабиблиотеке — файл переиспользуемый.'),
        ];
    }
}
