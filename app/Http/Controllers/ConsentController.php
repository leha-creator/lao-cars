<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ConsentPageContent;
use Illuminate\Contracts\View\View;

/**
 * Согласие на обработку персональных данных.
 *
 * Одиночное действие и тонкий контроллер — по образцу `PrivacyController`:
 * документ читают, им не управляют, а данные к форме приводит сервис.
 */
final class ConsentController extends Controller
{
    public function __invoke(ConsentPageContent $content): View
    {
        return view('legal.consent', $content->build() + ['title' => ConsentPageContent::TITLE]);
    }
}
