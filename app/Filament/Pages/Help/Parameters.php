<?php

declare(strict_types=1);

namespace App\Filament\Pages\Help;

use App\Support\Help\HelpSection;

/**
 * «Настройка параметров» — ответ на вопрос «мне надо изменить,
 * как устроен сайт».
 *
 * Класс зовётся `Parameters`, а не `Settings`: рядом уже живёт страница
 * `App\Filament\Pages\ManageSiteSettings`, и два похожих имени в одном
 * разделе панели — это гарантированная правка не того файла. Слаг при
 * этом `help/settings` — он отражает раздел справки, а не имя класса.
 */
final class Parameters extends HelpPage
{
    protected static ?string $slug = 'help/settings';

    protected static ?int $navigationSort = 2;

    public static function section(): HelpSection
    {
        return HelpSection::Settings;
    }
}
