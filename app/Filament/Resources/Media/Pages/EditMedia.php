<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\Actions\DeleteMediaAction;
use App\Filament\Resources\Media\MediaResource;
use Filament\Resources\Pages\EditRecord;

final class EditMedia extends EditRecord
{
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Именно защищённое действие: голый DeleteAction здесь удалил
            // бы используемое изображение в обход проверки из списка.
            DeleteMediaAction::make(),
        ];
    }
}
