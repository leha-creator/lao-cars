<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\SocialLinks;
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
    public function index(): View
    {
        return view('contacts.index', [
            'contacts' => Setting::group('contacts'),
            'socials' => SocialLinks::from(Setting::group('socials')),
        ]);
    }
}
