<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Setting;
use App\Support\SiteMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * Шапка публичной части (веха 4.1).
 *
 * Классовый компонент, а не partial с `Setting::get()` в шаблоне: `ARCHITECTURE.md`
 * прямо запрещает Blade ходить в БД. Прокидывать контакты из контроллеров тоже
 * нельзя — шапку рендерит layout на каждой странице, и забытый контроллер дал бы
 * шапку без телефона молча и только на одной странице. Прецедент компонента,
 * который сам берёт свои данные, в проекте есть — это `LeadForm` вехи 3.7.
 *
 * Настройки читаются через кеш `Setting` (одна выборка на запрос на всё
 * приложение), поэтому лишних запросов на страницу шапка не добавляет — на это
 * стоит сторож в `LayoutTest`.
 *
 * Два состояния вместо двух компонентов: на внутренних страницах шапка липкая,
 * с блюром и нижней границей; на главной — прозрачная, внутри хиро, поверх
 * фонового изображения (`$overlay = true`). Копия шапки под главную разошлась бы
 * с оригиналом на первом же изменении меню. До вехи 4.2, которая соберёт хиро,
 * ветка `overlay` потребителя не имеет — это осознанная плата, а не забытый код.
 */
final class SiteHeader extends Component
{
    /**
     * Телефон из настроек или `null`, если он не заполнен.
     *
     * Резолвится один раз в конструкторе, а не в методе: шаблон обращается
     * к телефону дважды (подпись и `href`), и предупреждение о незаполненной
     * настройке иначе писалось бы в лог по два раза на страницу.
     */
    public ?string $phone;

    public function __construct(public bool $overlay = false)
    {
        $this->phone = self::resolvePhone();
    }

    /**
     * Пункты меню — из общего источника (`SiteMenu`), а не из локального массива.
     *
     * @return list<array{name: string, label: string, url: string}>
     */
    public function items(): array
    {
        return SiteMenu::header();
    }

    /**
     * Активен ли раздел.
     *
     * Сравнение идёт по имени роута с маской, а не по `url()->current()`:
     * карточка автомобиля `/catalog/{car}` обязана подсвечивать «Каталог»,
     * а сравнение адресов этого не даёт. `catalog.index` → маска `catalog.*`.
     */
    public function isActive(string $routeName): bool
    {
        return request()->routeIs(Str::before($routeName, '.').'.*');
    }

    /**
     * Адрес для ссылки `tel:` — без пробелов, скобок и дефисов.
     */
    public function phoneHref(): ?string
    {
        return $this->phone === null ? null : preg_replace('/[^\d+]/', '', $this->phone);
    }

    public function render(): View
    {
        return view('components.site-header');
    }

    /**
     * Пустая ссылка `tel:` хуже отсутствующей — она выглядит рабочей, поэтому
     * незаполненный телефон убирает блок целиком.
     *
     * WARN пишется на каждый рендер осознанно: это конфигурационная ошибка,
     * которая чинится один раз, а тихий фолбэк означал бы сайт без телефона,
     * которого никто не заметил, — при том что весь сайт существует ради заявок.
     */
    private static function resolvePhone(): ?string
    {
        $phone = Setting::get('contacts.phone');

        if ($phone === null || $phone === '') {
            Log::warning('[Layout] в настройках сайта не заполнен телефон', [
                'setting' => 'contacts.phone',
            ]);

            return null;
        }

        return (string) $phone;
    }
}
