# Публичные формы: валидация и защита

Формы заявок открыты всему интернету и собирают персональные данные (имя, телефон).
Три обязательных слоя: CSRF, rate limiting, honeypot.

## FormRequest

Валидация живёт здесь, а не в контроллере.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Car;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // публичная форма
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:120'],
            'phone'   => ['required', 'string', 'regex:/^\+?[0-9\s\-\(\)]{10,20}$/'],
            'email'   => ['nullable', 'email:rfc', 'max:180'],
            'message' => ['nullable', 'string', 'max:2000'],

            // Источник приходит скрытыми полями формы
            'source_type' => ['nullable', Rule::in(['car', 'service'])],
            'source_id'   => ['nullable', 'integer', 'required_with:source_type'],

            // Honeypot `website` в правилах отсутствует намеренно — см. ниже.
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'  => 'Укажите, как к вам обращаться.',
            'phone.required' => 'Телефон обязателен — по нему свяжется менеджер.',
            'phone.regex'    => 'Проверьте формат телефона.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => trim((string) $this->input('phone')),
        ]);
    }
}
```

**Honeypot отбрасывает бота молча, а не ошибкой валидации.** `'website' => ['prohibited']`
выглядит короче, но сообщает боту имя поля-ловушки: ловушка, которая себя называет,
перестаёт работать после первого прогона. Поле не попадает в `rules()` вовсе, а проверяет
его отдельный метод; контроллер отдаёт боту тот же редирект с тем же сообщением об успехе,
что и человеку, и ничего не пишет в БД:

```php
// StoreLeadRequest
public function isSpam(): bool
{
    return filled($this->input('website'));
}

// LeadController::store()
if ($request->isSpam()) {
    Log::channel('leads')->debug('[Lead] заявка отброшена по honeypot', ['ip' => $request->ip()]);

    return back()->with('status', 'Заявка принята — менеджер свяжется с вами.');
}
```

Уровень DEBUG, а не WARN: ботов много, и WARN забил бы лог.

### Проверка существования источника

`source_id` приходит из скрытого поля и не заслуживает доверия — подставленный чужой id
привяжет лид к произвольной записи:

```php
public function withValidator($validator): void
{
    $validator->after(function ($validator): void {
        $type = $this->input('source_type');
        $id   = $this->input('source_id');

        if ($type === null) {
            return;
        }

        $exists = match ($type) {
            'car'     => Car::whereKey($id)->exists(),
            'service' => Service::whereKey($id)->exists(),
            default   => false,
        };

        if (! $exists) {
            $validator->errors()->add('source_id', 'Источник заявки не найден.');
        }
    });
}
```

## Rate limiting

В Laravel 11+ лимитеры регистрируются в `AppServiceProvider::boot()` — отдельного
`RouteServiceProvider` в скелете больше нет.

```php
// App\Providers\AppServiceProvider::boot()
RateLimiter::for('leads', function (Request $request): Limit {
    return Limit::perMinute(5)
        ->by($request->ip())
        ->response(fn (): Response => response('Слишком много заявок. Попробуйте через минуту.', 429));
});
```

```php
// routes/web.php
Route::post('/leads', StoreLeadController::class)
    ->middleware('throttle:leads')
    ->name('leads.store');
```

5 заявок в минуту с IP — с запасом для живого человека, который отправил форму дважды, и
тесно для скрипта. За NAT (офис, мобильный оператор) лимит общий на всех — если появятся
жалобы, добавляйте в ключ телефон: `->by($request->ip().'|'.$request->input('phone'))`.

## Blade-форма

```blade
<form method="POST" action="{{ route('leads.store') }}" x-data="{ sending: false }"
      @submit="sending = true">
    @csrf

    {{-- Источник заявки: карточка авто или услуга --}}
    @isset($source)
        <input type="hidden" name="source_type" value="{{ $sourceType }}">
        <input type="hidden" name="source_id" value="{{ $source->id }}">
    @endisset

    {{-- Скрытого поля `page_url` здесь нет намеренно: адрес страницы
         определяет сервер (см. ниже). --}}

    {{-- Honeypot: уводится за пределы экрана, а не прячется display:none --}}
    <div class="absolute -left-[9999px]" aria-hidden="true">
        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <input type="text" name="name" value="{{ old('name') }}" placeholder="Ваше имя" required>
    @error('name') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror

    <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="+7 (___) ___-__-__" required>
    @error('phone') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror

    <textarea name="message" placeholder="Комментарий">{{ old('message') }}</textarea>

    <button type="submit" :disabled="sending" x-text="sending ? 'Отправляем…' : 'Оставить заявку'"></button>
</form>
```

Honeypot уводится за пределы экрана (`absolute -left-[9999px]`), а не прячется
через `class="hidden"` или `type="hidden"`: `display:none` боты распознают,
а hidden-поля пропускают.

`old()` во всех полях — при ошибке валидации клиент не должен вводить телефон заново.

### Адрес страницы определяет сервер

Скрытого поля `page_url` в форме нет и быть не должно. Значение уходит **ссылкой
в Telegram менеджеру**, и клиентское поле превращает уведомление в вектор фишинга:
менеджер видит «Страница: …» и кликает. Адрес берётся из сессии на стороне сервера,
проверяется на принадлежность своему хосту и обрезается до длины колонки:

```php
// StoreLeadRequest
private function pageUrl(): ?string
{
    $previous = url()->previous();

    $host = parse_url($previous, PHP_URL_HOST);
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    if (! is_string($host) || ! is_string($appHost) || $host !== $appHost) {
        return null;
    }

    // `page_url` — varchar(255), и PostgreSQL считает в нём символы,
    // а не байты: substr() на кириллическом адресе режет посреди символа.
    return mb_substr($previous, 0, 255);
}
```

Плата за решение: отправка формы из вкладки, открытой без предшествующего GET,
даёт адрес корня вместо страницы. Лид при этом не теряется, менеджер лишается
контекста. Если контекст окажется критичным, правильный ход — подписанное поле,
а не сырое.

## Единый партиал

Все формы сайта — один Blade-компонент с разным источником:

```blade
{{-- В карточке авто --}}
<x-lead-form :source="$car" title="Оставить заявку на этот автомобиль" />

{{-- На странице услуги --}}
<x-lead-form :source="$service" title="Записаться" />

{{-- В контактах и на главной --}}
<x-lead-form title="Обратный звонок" />

{{-- Подбор запчасти: те же поля плюс марка, модель и VIN --}}
<x-lead-form :parts="true" title="Подобрать запчасть" />
```

Атрибута `source-type` у компонента нет: алиас берётся из самой модели
(`$source->getMorphClass()`), потому что morph map включён в `AppServiceProvider`.
Второй словарь «класс → алиас» в шаблоне разошёлся бы с первым.

## Чеклист безопасности формы

- [ ] `@csrf` в форме, роут в группе `web`
- [ ] `throttle:leads` на роуте, отказ — редирект с ошибкой на форме, а не голый 429
- [ ] Honeypot уведён за экран (не `display:none`, не `type="hidden"`) и отбрасывает молча
- [ ] `page_url` определяет сервер, скрытого поля в форме нет
- [ ] `source_id` проверяется на существование, а не берётся на веру
      (для услуги — ещё и на `is_published`, для авто — только существование)
- [ ] Валидация в FormRequest, не в контроллере
- [ ] Ограничения длины не мягче колонок: `phone` 32, `part_vin` 17, `page_url` 255
- [ ] `old()` во всех полях
- [ ] В логи не пишутся телефон и email клиента целиком
