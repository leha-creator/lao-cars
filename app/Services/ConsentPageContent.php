<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Support\Legal\ConsentText;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Facades\Log;

/**
 * Данные страницы согласия на обработку персональных данных.
 *
 * Парный сервис к `PrivacyPageContent`, и граница у него та же: тело
 * проверяется на пустоту строгим сравнением, версия и дата приводятся
 * к `null` вместо пустых строк, а текст проходит санитайзер редактора —
 * причина последнего записана в докблоке `PrivacyPageContent::sanitized()`
 * и относится к этому классу слово в слово.
 *
 * Общего родителя у двух сервисов нет намеренно. Различие между ними —
 * проверка политики на незаполненные реквизиты, которой у согласия нет:
 * реквизиты в его тексте подставлены с первой редакции. Базовый класс
 * ради двух коротких приватных методов спрятал бы это различие за
 * наследованием.
 */
final class ConsentPageContent
{
    /**
     * Заголовок страницы — константа, а не настройка: по нему документ
     * опознают, и он же стоит в подписи чекбокса формы заявки.
     */
    public const string TITLE = 'Согласие на обработку персональных данных';

    /**
     * @return array{body: ?string, version: ?string, effectiveOn: ?string}
     */
    public function build(): array
    {
        $setting = Setting::get(ConsentText::SETTING_KEY);

        $body = self::text(data_get($setting, 'body'));
        $version = self::text(data_get($setting, 'version'));
        $effectiveOn = self::text(data_get($setting, 'effective_on'));

        if ($body !== null) {
            $body = RichContentRenderer::make($body)->toHtml();
        }

        Log::debug('[Согласие] собрана страница', [
            'version' => $version,
            'effective_on' => $effectiveOn,
            'body_length' => $body === null ? 0 : mb_strlen($body),
        ]);

        if ($body === null) {
            // 404 здесь запрещён по той же причине, что у политики: на этот
            // адрес ведёт чекбокс согласия в каждой форме сайта. Но пустой
            // текст согласия хуже пустой политики — человек ставит отметку
            // под документом, которого нет, — поэтому запись идёт с ключом
            // настройки: по ней видно, что на проде не выполнена
            // `laocars:install-legal-settings`.
            Log::warning('[Согласие] текст документа пуст — посетитель даёт согласие, не видя его условий', [
                'setting' => ConsentText::SETTING_KEY,
            ]);
        }

        return [
            'body' => $body,
            'version' => $version,
            'effectiveOn' => $effectiveOn,
        ];
    }

    /**
     * Непустая строка или `null` — строго, без `empty()` (правило `RULES.md`).
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
