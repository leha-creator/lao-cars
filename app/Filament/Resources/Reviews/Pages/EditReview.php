<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\Actions\PublishReviewAction;
use App\Filament\Resources\Reviews\ReviewResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditReview extends EditRecord
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Те же экземпляры действий, что и в списке: копия проверки
            // и логирования здесь однажды разошлась бы с оригиналом.
            PublishReviewAction::publish(),
            PublishReviewAction::unpublish(),
            DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }
}
