<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ContactsPageContent;
use App\Services\OrganizationStructuredData;
use Illuminate\Contracts\View\View;

/**
 * Страница контактов (веха 4.5).
 *
 * Сборку делает `ContactsPageContent`, а не этот метод, и причина записана
 * там же, в PHPDoc сервиса: данные надо привести к форме, прежде чем отдать
 * в Blade (адреса `tel:`/`mailto:`, семь строк расписания, адрес карты
 * с фолбэком), — это граница `ARCHITECTURE.md`. Прецедент — `AboutPageContent`
 * той же вехи; противоположный случай, где сервиса нет намеренно, —
 * `PartsController`.
 *
 * Микроразметка организации приходит вторым сервисом (`OrganizationStructuredData`,
 * веха 4.14) и в сборку страницы не складывается: словари schema.org
 * не место держать ни в Blade, ни на уровень глубже, чем нужно, чтобы
 * их найти.
 */
final class ContactController extends Controller
{
    public function index(ContactsPageContent $content, OrganizationStructuredData $structuredData): View
    {
        return view('contacts.index', [
            ...$content->build(),
            'structuredData' => $structuredData->forContactsPage(),
        ]);
    }
}
