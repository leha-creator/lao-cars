<?php

namespace App\Providers\Filament;

use App\Filament\NavigationGroup;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('ЛАО КАРС')
            ->login()
            // Своя учётная запись правится самим пользователем — следствие
            // появления ролей, а не удобство. `UserResource` доступен только
            // администратору, поэтому без страницы профиля менеджер не может
            // сменить себе пароль, и придуманный администратором пароль
            // остаётся известен администратору бессрочно.
            //
            // Политика этой странице не нужна: `Page::canAccess()` пускает
            // любого аутентифицированного, и здесь это верно — править можно
            // только себя, а штатная `EditProfile` требует подтверждения
            // текущим паролем.
            ->profile()
            // Без строгого режима отсутствие политики означает «разрешено»:
            // `Filament\get_authorization_response()` при отсутствующей
            // политике или отсутствующем методе доходит до `Response::allow()`
            // (vendor/filament/filament/src/helpers.php). То есть забытая
            // политика на новом ресурсе не отберёт доступ — она откроет его
            // всем, и заметить это можно только зайдя менеджером туда, куда
            // он попасть не должен.
            //
            // Строгий режим превращает пропуск в LogicException при первом же
            // обращении. Плата известна: политика обязана реализовать все
            // проверяемые методы, включая `reorder` (его дёргают все
            // reorderable-таблицы) и `deleteAny` (его дёргает DeleteBulkAction).
            ->strictAuthorization()
            ->colors([
                'primary' => Color::Amber,
            ])
            // Порядок групп в меню фиксируется порядком кейсов enum-а.
            // Без явной регистрации группы встают в порядке обнаружения
            // ресурсов, и меню перетасовывается от каждого нового файла.
            ->navigationGroups(NavigationGroup::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            // Подписка браузера на push-уведомления (веха 4.7). Панель
            // работает на собственной сборке и бандл публичной части
            // не подключает, поэтому скрипт приходит сюда отдельной точкой
            // входа Vite, а не импортом в `app.js`.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.push-subscribe')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
