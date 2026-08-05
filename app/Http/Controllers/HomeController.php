<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\HomeContent;
use Illuminate\Contracts\View\View;

/**
 * Главная страница.
 *
 * Контроллер тонкий намеренно: данные собирает `HomeContent`, а не этот
 * метод. Соблазн «упростить» сервис обратно сюда возникает у каждого, кто
 * открывает файл из трёх строк, поэтому причина записана явно — на главной
 * шесть источников (подборка авто, три ключа настроек с jsonb-значениями
 * разной формы, разрешение `image_id` промо в URL медиабиблиотеки
 * и SEO-заголовки), и половина из них требует нормализации: форма настроек
 * пишет свои ключи даже пустыми, а очищенный репитер приходит `null`.
 * Прецедент в проекте — `CatalogFilterOptions`. Побочная выгода та же:
 * блоки главной проверяются без HTTP.
 *
 * Сервис приходит через контейнер, а не через `new`: правило
 * `ARCHITECTURE.md`, без него его не подменить в тесте.
 */
final class HomeController extends Controller
{
    public function index(HomeContent $content): View
    {
        return view('home.index', $content->build());
    }
}
