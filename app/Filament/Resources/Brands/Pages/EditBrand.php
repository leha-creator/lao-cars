<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\Actions\DeleteBrandAction;
use App\Filament\Resources\Brands\BrandResource;
use Filament\Resources\Pages\EditRecord;

final class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Именно защищённое действие: голый DeleteAction здесь дал бы
            // 500 на марке с автомобилями в обход проверки из списка.
            DeleteBrandAction::make(),
        ];
    }
}
