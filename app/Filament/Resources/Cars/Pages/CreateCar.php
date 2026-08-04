<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars\Pages;

use App\Filament\Resources\Cars\CarResource;
use App\Filament\Resources\Cars\Concerns\HandlesCarRelations;
use Filament\Resources\Pages\CreateRecord;

final class CreateCar extends CreateRecord
{
    use HandlesCarRelations;

    protected static string $resource = CarResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractCarRelations($data);
    }

    protected function afterCreate(): void
    {
        // Только здесь, а не раньше: до записи у автомобиля нет `id`,
        // и привязывать к нему фотографии не к чему.
        $this->syncCarRelations();
    }
}
