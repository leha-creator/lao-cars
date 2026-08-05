<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\View\View;

/**
 * Страница автосервиса.
 *
 * Заглушка вехи 4.1: заголовок, вводный текст из настроек и форма записи
 * на каркасе. Блоки по категориям (ТО и ремонт, шиномонтаж, детейлинг,
 * доп. сервисы) с прайсом и выбором услуги приходят вехой 4.4.
 *
 * Страница заведена сейчас, потому что «Автосервис» — закреплённый пункт
 * меню: без адреса он вёл бы в никуда, а подсветку активного раздела
 * было бы не на чем проверить.
 *
 * Настройки читает контроллер и передаёт готовыми — правило «Blade
 * не ходит в БД». Исключение сделано только для шапки и подвала
 * (см. PHPDoc `SiteHeader`) и на страницы не распространяется.
 */
final class ServiceController extends Controller
{
    public function index(): View
    {
        return view('services.index', [
            'title' => Setting::get('services_page.intro_title', 'Автосервис'),
            'intro' => Setting::get('services_page.intro_text'),
        ]);
    }
}
