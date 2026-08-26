<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PrivacyPageContent;
use Illuminate\Contracts\View\View;

/**
 * Политика обработки персональных данных (веха 4.17).
 *
 * Одиночное действие (`__invoke`), а не метод `index()`: у страницы одно
 * действие и второго не появится — документ читают, им не управляют.
 *
 * Контроллер тонкий: данные собирает `PrivacyPageContent`. Граница
 * проходит там же, где у `AboutController` и `ContactController`, и
 * причина записана в PHPDoc сервиса — значение настройки надо проверить
 * на пустоту, привести версию и дату к `null` и просмотреть текст на
 * незаполненные реквизиты, прежде чем отдать в Blade. Сервис приходит
 * через контейнер, а не через `new`: правило `ARCHITECTURE.md`, без него
 * его не подменить в тесте.
 */
final class PrivacyController extends Controller
{
    public function __invoke(PrivacyPageContent $content): View
    {
        return view('legal.privacy', $content->build() + ['title' => PrivacyPageContent::TITLE]);
    }
}
