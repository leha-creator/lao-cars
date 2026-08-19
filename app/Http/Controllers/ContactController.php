<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\OrganizationStructuredData;
use App\Support\SocialLinks;
use App\Support\WorkSchedule;
use Illuminate\Contracts\View\View;

/**
 * Страница контактов.
 *
 * Заглушка вехи 4.1: контакты из настроек и форма обратной связи
 * на каркасе. Полная вёрстка приходит вехой 4.5 — макета для этой
 * страницы нет, она собирается по UI Kit.
 *
 * Карты в MVP нет осознанно: Яндекс.Карты отложены за его пределы
 * (`DESCRIPTION.md`), до подключения адрес заменяет карту.
 */
final class ContactController extends Controller
{
    public function index(OrganizationStructuredData $structuredData): View
    {
        $contacts = Setting::group('contacts');

        return view('contacts.index', [
            'contacts' => $contacts,
            'socials' => SocialLinks::from(Setting::group('socials')),
            // Расписание собирается здесь, а не в шаблоне: строка на
            // странице и часы в микроразметке обязаны прийти из одного
            // значения, иначе однажды разойдутся — и разойдутся молча,
            // потому что видно будет только одно из двух.
            'schedule' => WorkSchedule::fromSetting($contacts['contacts.schedule'] ?? null),
            // schema.org в разметке — это словари, которые не место
            // держать в Blade (прецедент — `CarStructuredData`).
            'structuredData' => $structuredData->forContactsPage(),
        ]);
    }
}
