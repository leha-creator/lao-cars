{{--
    Форма заявки — функциональный шаблон без дизайна.

    Вёрстка по макету приходит вехой 4.1. Здесь проверяется другое: что
    путь заявки работает на живой странице, а не только в тестах —
    источник доезжает до БД, ошибки валидации возвращаются с сохранённым
    вводом, а honeypot отбрасывает бота молча.

    Пока вёрстки нет, форма не должна попадать в публичный релиз: на проде
    она выглядит как сломанный сайт.

    Скрытого поля `page_url` здесь нет намеренно. Адрес страницы определяет
    сервер (`StoreLeadRequest::pageUrl()`): значение уходит ссылкой
    в Telegram менеджеру, и клиентское поле превратило бы уведомление
    в вектор фишинга — менеджер видит «Страница: …» и кликает.
--}}
<section class="mt-8 border-t pt-6">
    <h2 class="font-medium">{{ $title }}</h2>

    @if (session('status'))
        {{-- Одно и то же сообщение видят и человек, и бот, заполнивший
             ловушку: ответ, отличающийся от успеха, выдал бы ловушку. --}}
        <p class="mt-2 text-green-700">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('leads.store') }}" class="mt-4 grid max-w-lg gap-3">
        @csrf

        @if ($source !== null)
            {{-- Алиасы morph map (`car` / `service`) — ровно то, что лежит
                 в `leads.source_type`. Значение сервер перепроверяет:
                 подделать скрытое поле — вопрос одной правки в devtools. --}}
            <input type="hidden" name="source_type" value="{{ $sourceType() }}">
            <input type="hidden" name="source_id" value="{{ $source->getKey() }}">
        @endif

        {{-- Ловушка для ботов. Уводится за пределы экрана, а не прячется
             через `class="hidden"` или `type="hidden"`: `display:none` боты
             распознают, а hidden-поля пропускают. `tabindex="-1"`
             и `autocomplete="off"` — чтобы в неё не заехал живой человек
             с клавиатуры или автозаполнением. --}}
        <div class="absolute -left-[9999px]" aria-hidden="true">
            <label>
                Сайт
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
            </label>
        </div>

        <label class="grid gap-1">
            <span>Имя</span>
            {{-- `old()` во всех полях без исключения: при ошибке валидации
                 клиент не должен вводить телефон и комментарий заново. --}}
            <input type="text" name="name" value="{{ old('name') }}" required class="border p-2">
            @error('name')
                <span class="text-sm text-red-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="grid gap-1">
            <span>Телефон</span>
            <input type="tel" name="phone" value="{{ old('phone') }}" required class="border p-2">
            @error('phone')
                <span class="text-sm text-red-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="grid gap-1">
            <span>E-mail</span>
            <input type="email" name="email" value="{{ old('email') }}" class="border p-2">
            @error('email')
                <span class="text-sm text-red-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="grid gap-1">
            <span>Удобный способ связи</span>
            <select name="contact_method" class="border p-2">
                <option value="">Не важно</option>
                @foreach (App\Enums\ContactMethod::options() as $value => $label)
                    <option value="{{ $value }}" @selected(old('contact_method') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('contact_method')
                <span class="text-sm text-red-700">{{ $message }}</span>
            @enderror
        </label>

        <label class="grid gap-1">
            <span>Удобное время звонка</span>
            <select name="preferred_time" class="border p-2">
                <option value="">Не важно</option>
                @foreach (App\Enums\PreferredTime::options() as $value => $label)
                    <option value="{{ $value }}" @selected(old('preferred_time') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('preferred_time')
                <span class="text-sm text-red-700">{{ $message }}</span>
            @enderror
        </label>

        @if ($parts)
            {{-- Поля подбора запчасти: по ним заявка отличается от прочих
                 (`Lead::isPartsRequest()`), а не источником. --}}
            <label class="grid gap-1">
                <span>Марка автомобиля</span>
                <input type="text" name="part_brand" value="{{ old('part_brand') }}" class="border p-2">
                @error('part_brand')
                    <span class="text-sm text-red-700">{{ $message }}</span>
                @enderror
            </label>

            <label class="grid gap-1">
                <span>Модель</span>
                <input type="text" name="part_model" value="{{ old('part_model') }}" class="border p-2">
                @error('part_model')
                    <span class="text-sm text-red-700">{{ $message }}</span>
                @enderror
            </label>

            <label class="grid gap-1">
                <span>VIN</span>
                {{-- 17 символов — не только стандарт VIN, но и длина
                     колонки: правило валидации мягче колонки означало бы
                     ошибку драйвера PostgreSQL вместо сообщения на форме. --}}
                <input type="text" name="part_vin" value="{{ old('part_vin') }}" maxlength="17" class="border p-2">
                @error('part_vin')
                    <span class="text-sm text-red-700">{{ $message }}</span>
                @enderror
            </label>
        @endif

        <label class="grid gap-1">
            <span>Комментарий</span>
            <textarea name="message" rows="4" class="border p-2">{{ old('message') }}</textarea>
            @error('message')
                <span class="text-sm text-red-700">{{ $message }}</span>
            @enderror
        </label>

        @error('source_id')
            <span class="text-sm text-red-700">{{ $message }}</span>
        @enderror

        <button type="submit" class="border p-2 font-medium">{{ $submit }}</button>
    </form>
</section>
