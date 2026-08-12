<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CarStatus;
use App\Enums\CatalogSort;
use App\Enums\EngineType;
use App\Services\CatalogCriteria;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Валидация GET-контракта каталога (веха 3.6).
 *
 * Правила намеренно широкие — типы и границы, а не бизнес-условия:
 * под редирект должно быть трудно попасть осмысленным пользовательским
 * вводом.
 */
final class CatalogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Марка передаётся slug-ом: id в адресной строке — мусор,
            // который не переносится между окружениями.
            'brand' => ['nullable', 'string', 'exists:brands,slug'],

            'engine' => ['nullable', Rule::enum(EngineType::class)],

            'year_from' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'year_to' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],

            'price_from' => ['nullable', 'integer', 'min:0'],
            'price_to' => ['nullable', 'integer', 'min:0'],

            // Список из трёх значений, а не из четырёх: `?status=sold`
            // не открывает доступ к проданным автомобилям в выдаче.
            // Скоуп `available()` применяется всегда, а `status` может
            // лишь сузить его.
            //
            // Перечисление остаётся ручным намеренно. Собрать список
            // из `CarStatus::cases()` «чтобы не забывать новый статус» —
            // ровно та правка, которая снимет ограничение по «Продан»,
            // и снимет его молча: тесты останутся зелёными, а выдача
            // начнёт отдавать проданные автомобили по прямой ссылке.
            'status' => ['nullable', Rule::in([
                CarStatus::InStock->value,
                CarStatus::OnOrder->value,
                CarStatus::InTransit->value,
            ])],

            'sort' => ['nullable', Rule::enum(CatalogSort::class)],

            // Динамические характеристики живут в пространстве имён
            // `attr[]`: ключ характеристики заводит администратор, и форма
            // вехи 3.4 разрешает любой `^[a-z][a-z0-9_]*$` — включая
            // `price`, `status` и `sort`. Плоский `?body_type=Седан`
            // красивее ровно до того дня, когда кто-то заведёт
            // характеристику с ключом `year`.
            'attr' => ['nullable', 'array'],
            'attr.*' => ['nullable', 'string', 'max:255'],

            // Правила для `page` здесь нет намеренно: пагинатор Laravel
            // сам приводит значение к числу и откатывается на первую
            // страницу, поэтому `integer|min:1` не защищает ни от чего,
            // зато добавляет редирект на каждый заход сканера
            // с мусорным `?page=`. Выход за последнюю страницу
            // обрабатывает контроллер — он отдаёт 404.
        ];
    }

    public function toCriteria(): CatalogCriteria
    {
        return CatalogCriteria::fromArray($this->validated());
    }

    /**
     * Битый параметр ведёт на чистый каталог, а не в 500 и не в
     * редирект-в-никуда.
     *
     * Штатное поведение FormRequest при провале — редирект назад
     * с ошибками; для публичной GET-страницы «назад» часто нет вовсе,
     * и пользователь уезжает на `/`. Цена решения честная: если из десяти
     * параметров невалиден один, теряются все десять. Для ссылки, которую
     * в 99% случаев прислал бот или сохранил браузер год назад, это
     * правильный размен.
     */
    protected function failedValidation(Validator $validator): void
    {
        // Уровень именно DEBUG: основной источник таких запросов —
        // сканеры, и WARN забил бы лог. Пишутся ключи, а не значения:
        // значение приходит из адресной строки и в лог не просится.
        Log::debug('[CatalogFilter] параметры отброшены, редирект на чистый каталог', [
            'invalid' => array_keys($validator->errors()->toArray()),
        ]);

        throw new HttpResponseException(redirect()->route('catalog.index'));
    }
}
