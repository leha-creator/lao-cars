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

            // Honeypot: реальный пользователь это поле не видит и не заполняет
            'website' => ['prohibited'],
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

`prohibited` на honeypot возвращает ошибку валидации ботам, не создавая лид. Если нужно,
чтобы бот не понимал, что его отсекли, — убирайте поле из правил и проверяйте вручную в
контроллере, отдавая обычный success-ответ без записи в БД.

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

    <input type="hidden" name="page_url" value="{{ url()->current() }}">

    {{-- Honeypot: скрыт от людей, но заполняется ботами --}}
    <div class="hidden" aria-hidden="true">
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

Скрывать honeypot нужно классом в CSS, а не `type="hidden"`: боты обычно пропускают
hidden-поля, но заполняют видимые в разметке текстовые.

`old()` во всех полях — при ошибке валидации клиент не должен вводить телефон заново.

## Единый партиал

Все формы сайта — один Blade-компонент с разным источником:

```blade
{{-- В карточке авто --}}
<x-lead-form :source="$car" source-type="car" title="Оставить заявку на этот автомобиль" />

{{-- На странице услуги --}}
<x-lead-form :source="$service" source-type="service" title="Записаться" />

{{-- В контактах и на главной --}}
<x-lead-form title="Обратный звонок" />
```

## Чеклист безопасности формы

- [ ] `@csrf` в форме, роут в группе `web`
- [ ] `throttle:leads` на роуте
- [ ] Honeypot скрыт классом, а не `type="hidden"`
- [ ] `source_id` проверяется на существование, а не берётся на веру
- [ ] Валидация в FormRequest, не в контроллере
- [ ] `old()` во всех полях
- [ ] В логи не пишутся телефон и email клиента целиком
