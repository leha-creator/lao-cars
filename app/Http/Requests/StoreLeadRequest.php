<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ContactMethod;
use App\Enums\PreferredTime;
use App\Models\Car;
use App\Models\Service;
use App\Services\LeadData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Валидация формы заявки (веха 3.7).
 *
 * Ограничения длины намеренно не мягче колонок таблицы `leads`:
 * `phone` — `varchar(32)`, `part_vin` — `varchar(17)`, `page_url` —
 * `varchar(255)`. Правило мягче колонки означает ошибку драйвера
 * PostgreSQL вместо сообщения на форме, то есть потерянный лид.
 */
final class StoreLeadRequest extends FormRequest
{
    /**
     * Форма публичная: заявку оставляет кто угодно без авторизации.
     */
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
            'name' => ['required', 'string', 'max:120'],

            // Формат телефона проверяется свободно: единого написания у
            // российского номера нет, и строгая маска отсекает живых людей
            // ради чистоты данных, которую менеджер всё равно правит
            // голосом. Максимум 32 — длина колонки.
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{10,20}$/'],

            'email' => ['nullable', 'email:rfc', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],

            'contact_method' => ['nullable', Rule::enum(ContactMethod::class)],
            'preferred_time' => ['nullable', Rule::enum(PreferredTime::class)],

            // Поля подбора запчасти: заполняются только формой на странице
            // запчастей, у остальных форм их нет.
            'part_brand' => ['nullable', 'string', 'max:255'],
            'part_model' => ['nullable', 'string', 'max:255'],
            'part_vin' => ['nullable', 'string', 'max:17'],

            // Список алиасов выписан явно и обязан совпадать с morph map
            // из `AppServiceProvider::boot()`: `Relation::enforceMorphMap()`
            // хранит в `leads.source_type` именно `car` и `service`.
            //
            // Пара проверяется в одну сторону, и односторонность намеренная.
            // `source_id` без типа — сирота: заявка ссылается на запись,
            // про которую неизвестно, из какой она таблицы, и записать
            // такую нельзя. Тип без id, наоборот, безобиден и приходит
            // ШТАТНО: селект «Интересует» на странице автосервиса (веха 4.4)
            // стоит рядом со скрытым `source_type=service`, и вариант
            // «нужна консультация» отправляет тип с пустым id. Требовать
            // id в этом случае значило бы запретить посетителю НЕ выбирать
            // услугу — то есть сломать половину сценария формы. Дальше тип
            // без id отбрасывает `toData()`, чтобы в колонку не попал
            // полузаполненный указатель.
            'source_type' => ['nullable', 'in:car,service', 'required_with:source_id'],
            'source_id' => ['nullable', 'integer'],

            // Поля `website` в правилах нет намеренно — см. isSpam().
            // Поля `page_url` в правилах нет намеренно — см. pageUrl().
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите, как к вам обращаться.',
            'phone.required' => 'Укажите телефон — это единственный способ с вами связаться.',
            'phone.regex' => 'Телефон выглядит непохоже на номер. Например: +7 999 123-45-67',
        ];
    }

    /**
     * Проверка источника заявки.
     *
     * `source_id` приходит скрытым полем формы и доверия не заслуживает:
     * подделать его — вопрос одной правки в devtools.
     *
     * Правила для автомобиля и услуги разные, и разница не случайна:
     * проданный автомобиль сохраняет страницу (решение вехи 3.6 — карточка
     * живёт ради истории и SEO), поэтому заявка с неё легитимна и статус
     * не проверяется. Неопубликованной услуги нет ни на одной странице
     * сайта, значит заявка на неё может прийти только из подделанной формы.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $type = $this->input('source_type');
                $id = $this->input('source_id');

                if ($type === null || $id === null) {
                    return;
                }

                $exists = match ($type) {
                    'car' => Car::whereKey($id)->exists(),
                    'service' => Service::whereKey($id)->published()->exists(),
                    default => false,
                };

                if (! $exists) {
                    $validator->errors()->add('source_id', 'Заявка отправлена на несуществующий объект.');
                }
            },
        ];
    }

    /**
     * Заполненная ловушка — признак бота.
     *
     * Поля `website` нет в `rules()` намеренно: правило `prohibited`
     * вернуло бы боту ошибку валидации с именем поля-ловушки, и ловушка,
     * которая себя называет, перестаёт работать после первого прогона.
     * Ответ на заполненный honeypot формирует контроллер — тот же редирект
     * с тем же сообщением об успехе, что и человеку.
     */
    public function isSpam(): bool
    {
        return filled($this->input('website'));
    }

    public function toData(): LeadData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $contactMethod = $this->text($validated['contact_method'] ?? null);
        $preferredTime = $this->text($validated['preferred_time'] ?? null);
        $sourceId = $validated['source_id'] ?? null;

        return new LeadData(
            name: (string) $validated['name'],
            phone: (string) $validated['phone'],
            email: $this->text($validated['email'] ?? null),
            message: $this->text($validated['message'] ?? null),
            contactMethod: $contactMethod === null ? null : ContactMethod::tryFrom($contactMethod),
            preferredTime: $preferredTime === null ? null : PreferredTime::tryFrom($preferredTime),
            partBrand: $this->text($validated['part_brand'] ?? null),
            partModel: $this->text($validated['part_model'] ?? null),
            partVin: $this->text($validated['part_vin'] ?? null),
            // Тип без id в колонку не пишется. Значение `service` при пустом
            // `source_id` дало бы полузаполненный полиморфный указатель:
            // заявка читалась бы как «Общая форма» (morph с пустым id отдаёт
            // `null`), а колонка при этом врала бы про источник — и врала бы
            // только в базе, то есть там, где это заметят позже всего.
            sourceType: $sourceId === null ? null : $this->text($validated['source_type'] ?? null),
            sourceId: $sourceId === null ? null : (int) $sourceId,
            pageUrl: $this->pageUrl(),
        );
    }

    /**
     * Адрес страницы, с которой пришла форма, — определяется сервером.
     *
     * Скрытого поля `page_url` в форме нет и быть не должно: значение
     * уходит ссылкой в Telegram менеджеру, и клиентское поле превращает
     * уведомление в вектор фишинга — менеджер видит «Страница: …»
     * и кликает. `url()->previous()` берёт адрес из сессии, а её
     * заполняет сам Laravel и только на GET-запросах.
     *
     * Чужой хост отбрасывается: сессия — не гарантия, а через `Referer`
     * туда попадает внешний адрес.
     */
    private function pageUrl(): ?string
    {
        $previous = url()->previous();

        $host = parse_url($previous, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || ! is_string($appHost) || $host !== $appHost) {
            return null;
        }

        // Колонка — `varchar(255)`, а PostgreSQL считает в ней символы,
        // а не байты. `substr()` на кириллическом адресе (а отфильтрованный
        // каталог даёт длинные адреса) обрезал бы посреди символа —
        // тот же класс ошибки, что и `left()`/`substr()` в правилах проекта.
        return mb_substr($previous, 0, 255);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
