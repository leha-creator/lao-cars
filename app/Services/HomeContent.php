<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Car;
use App\Models\Media;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

/**
 * Данные главной страницы (веха 4.2).
 *
 * `ARCHITECTURE.md` разрешает контроллеру читать модель напрямую, когда
 * речь про чистое чтение и прослойка была бы пустым файлом. Здесь она
 * пустой не будет: на главной шесть источников — подборка авто, три ключа
 * настроек с jsonb-значениями разной формы, разрешение `image_id` промо
 * в URL медиабиблиотеки и SEO-заголовки. Есть что оркестрировать и есть
 * что нормализовать. Прецедент в проекте точный — `CatalogFilterOptions`:
 * так же собирает данные одной страницы в массив и так же не принимает
 * `Request`, поэтому блоки главной проверяются без HTTP.
 *
 * Нормализация здесь, а не в шаблоне, по одной причине: форма настроек
 * (веха 3.5) пишет свои ключи безусловно, даже пустыми — «очистить блок»
 * там рабочий сценарий, — а репитер при удалении всех элементов отдаёт
 * `null`, а не `[]`. Шаблон, написанный по сиду, падает на первом же
 * `foreach (null)`, и падает на проде.
 */
final class HomeContent
{
    /**
     * @return array{
     *     cars: EloquentCollection<int, Car>,
     *     ticker: list<string>,
     *     promo: ?array{title: ?string, text: ?string, link_text: ?string, link_url: ?string, image_url: ?string},
     *     advantages: list<array{number: ?string, title: string, text: ?string}>,
     *     seo: array{title: ?string, description: ?string},
     * }
     */
    public function build(): array
    {
        return [
            'cars' => $this->cars(),
            'ticker' => $this->ticker(),
            'promo' => $this->promo(),
            'advantages' => $this->advantages(),
            'seo' => $this->seo(),
        ];
    }

    /**
     * Подборка «на главной»: отмеченные администратором и при этом ещё
     * доступные к покупке.
     *
     * Пересечение делает сервис, а не скоуп `onHomepage()`: смысл скоупа —
     * «отмечен администратором», и ровно в этом значении его читают
     * `CarTest` и `SeedersTest`. Проданный автомобиль с отметкой — тупик:
     * карточка открывается, статус говорит «продан», покупать нечего.
     *
     * `with()` обязателен: без него сетка из шести карточек стоит
     * тринадцать запросов — карточка обращается и к марке, и к главному фото.
     *
     * @return EloquentCollection<int, Car>
     */
    private function cars(): EloquentCollection
    {
        $cars = Car::query()
            ->onHomepage()
            ->available()
            ->with(['brand', 'mainPhoto'])
            ->limit((int) config('catalog.homepage_limit'))
            ->get();

        if ($cars->isEmpty()) {
            // WARN на каждый рендер — осознанно, по прецеденту `SiteHeader`
            // с незаполненным телефоном: это конфигурационная ошибка,
            // которая чинится один раз, а тихий пропуск означал бы главную
            // без главного блока, которого никто не заметил.
            Log::warning('[Главная] в подборке нет ни одного автомобиля', [
                'hint' => 'отметьте автомобили галкой «Показывать на главной» в разделе «Автомобили» админки',
            ]);
        }

        return $cars;
    }

    /**
     * Бегущая строка: список непустых строк.
     *
     * @return list<string>
     */
    private function ticker(): array
    {
        $items = [];

        foreach ($this->listFrom(Setting::get('home.ticker')) as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);

            if ($text === '') {
                continue;
            }

            $items[] = $text;
        }

        return $items;
    }

    /**
     * Промо-баннер или `null`, если администратор очистил и заголовок, и текст.
     *
     * Проверка пустоты строгая (`=== null || === ''`), а не `empty()`:
     * правило `RULES.md` — `empty('0')` в PHP истинно.
     *
     * @return ?array{title: ?string, text: ?string, link_text: ?string, link_url: ?string, image_url: ?string}
     */
    private function promo(): ?array
    {
        $promo = Setting::get('home.promo');

        if (! is_array($promo)) {
            return null;
        }

        $title = $this->string($promo['title'] ?? null);
        $text = $this->string($promo['text'] ?? null);

        if ($title === null && $text === null) {
            return null;
        }

        return [
            'title' => $title,
            'text' => $text,
            'link_text' => $this->string($promo['link_text'] ?? null),
            'link_url' => $this->string($promo['link_url'] ?? null),
            'image_url' => $this->promoImageUrl($promo['image_id'] ?? null),
        ];
    }

    /**
     * Фон промо-блока: URL записи медиабиблиотеки или `null`.
     *
     * Штатным путём `null` при заполненном `image_id` не возникает —
     * удаление используемой записи блокирует проверка `Media::usages()`
     * (веха 3.5). Значит запись пропала в обход админки, и это стоит WARN:
     * блок отрендерится без фона, а не упадёт, и симптом иначе останется
     * незамеченным.
     */
    private function promoImageUrl(mixed $mediaId): ?string
    {
        if ($mediaId === null || $mediaId === '') {
            return null;
        }

        $media = Media::query()->find($mediaId);

        if ($media === null) {
            Log::warning('[Главная] фон промо-блока ссылается на удалённую запись медиабиблиотеки', [
                'setting' => 'home.promo.image_id',
                'media_id' => $mediaId,
            ]);

            return null;
        }

        return $media->url;
    }

    /**
     * Блок «Почему мы». Элемент без заголовка выпадает: карточка,
     * состоящая из одного номера, выглядит как поломка вёрстки.
     *
     * @return list<array{number: ?string, title: string, text: ?string}>
     */
    private function advantages(): array
    {
        $advantages = [];

        foreach ($this->listFrom(Setting::get('home.advantages')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->string($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $advantages[] = [
                'number' => $this->string($item['number'] ?? null),
                'title' => $title,
                'text' => $this->string($item['text'] ?? null),
            ];
        }

        return $advantages;
    }

    /**
     * SEO-заголовки по умолчанию (веха 3.5) получают первого потребителя.
     *
     * Пустая настройка приводится к `null`, чтобы сработал фолбэк шаблона:
     * иначе очищенный ключ дал бы пустой `<title>`. На остальные страницы
     * это не распространяется — у каталога, карточки и разделов свои
     * осмысленные заголовки, и общий фолбэк там маскировал бы забытую
     * секцию вместо того, чтобы её обнажить.
     *
     * @return array{title: ?string, description: ?string}
     */
    private function seo(): array
    {
        return [
            'title' => $this->string(Setting::get('seo.default_title')),
            'description' => $this->string(Setting::get('seo.default_description')),
        ];
    }

    /**
     * Значение репитера настроек в виде списка.
     *
     * Удаление всех элементов в форме настроек даёт `null`, а не `[]`, —
     * это и есть причина, по которой нормализация живёт в сервисе.
     *
     * @return list<mixed>
     */
    private function listFrom(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Непустая строка или `null`.
     */
    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
