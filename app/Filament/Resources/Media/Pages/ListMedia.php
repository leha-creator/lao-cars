<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Actions\HelpAction;
use App\Filament\Resources\Media\Actions\UploadMediaAction;
use App\Filament\Resources\Media\MediaResource;
use Filament\Resources\Pages\ListRecords;

final class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction здесь не годится: запись библиотеки без файла
            // бессмысленна, поэтому создание идёт через загрузку.
            UploadMediaAction::make(),
            HelpAction::make('media-library'),
        ];
    }
}
