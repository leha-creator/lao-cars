<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars\Pages;

use App\Filament\Resources\Cars\CarResource;
use App\Filament\Resources\Cars\Concerns\HandlesCarRelations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditCar extends EditRecord
{
    use HandlesCarRelations;

    protected static string $resource = CarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Вместе с автомобилем будут удалены все его фотографии (включая файлы на диске) и значения характеристик. Действие необратимо.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillCarAttributes($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractCarRelations($data);
    }

    protected function afterSave(): void
    {
        $this->syncCarRelations();
    }
}
