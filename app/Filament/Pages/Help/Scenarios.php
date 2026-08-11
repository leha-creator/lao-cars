<?php

declare(strict_types=1);

namespace App\Filament\Pages\Help;

use App\Support\Help\HelpSection;

/**
 * «Сценарии работы» — ответ на вопрос «мне надо сделать X».
 */
final class Scenarios extends HelpPage
{
    protected static ?string $slug = 'help/scenarios';

    protected static ?int $navigationSort = 1;

    public static function section(): HelpSection
    {
        return HelpSection::Scenarios;
    }
}
