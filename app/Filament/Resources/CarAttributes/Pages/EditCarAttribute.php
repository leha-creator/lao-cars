<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes\Pages;

use App\Filament\Resources\CarAttributes\Actions\DeleteCarAttributeAction;
use App\Filament\Resources\CarAttributes\CarAttributeResource;
use Filament\Resources\Pages\EditRecord;

final class EditCarAttribute extends EditRecord
{
    protected static string $resource = CarAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Голый DeleteAction здесь удалил бы значения у всех автомобилей
            // без единого слова об этом в подтверждении.
            DeleteCarAttributeAction::make(),
        ];
    }
}
