<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }
}
