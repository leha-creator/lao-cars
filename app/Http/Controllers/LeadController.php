<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Приём заявок со всех форм сайта (веха 3.7).
 *
 * Контроллер тонкий по правилу `ARCHITECTURE.md`: валидация — в
 * `StoreLeadRequest`, запись и уведомление — в `LeadService`. Именно из
 * толстых контроллеров теряются заявки.
 *
 * Вехой 4.7 у ответа появилась вторая форма — JSON вместо редиректа.
 * Набор полей, honeypot, серверный `page_url` и лимитер по IP при этом
 * не тронуты: меняется только то, чем контроллер отвечает.
 */
final class LeadController extends Controller
{
    /**
     * Текст успеха объявлен один раз на обе формы ответа.
     *
     * Две копии строки разъехались бы на первой же правке, и разошлись бы
     * они молча: путь без JavaScript и путь с `fetch` показывают её в разных
     * местах страницы, и рядом их никто никогда не видит.
     */
    private const ACCEPTED_MESSAGE = 'Заявка принята — менеджер свяжется с вами.';

    public function store(StoreLeadRequest $request, LeadService $leads): RedirectResponse|JsonResponse
    {
        if ($request->isSpam()) {
            // Бот получает ровно тот же ответ, что и человек. Ошибка
            // валидации сообщила бы ему имя поля-ловушки, и ловушка
            // перестала бы работать после первого прогона.
            // Уровень DEBUG, а не WARN: ботов много, и WARN забил бы лог.
            Log::channel('leads')->debug('[Lead] заявка отброшена по honeypot', [
                'ip' => $request->ip(),
                'page_url' => $request->headers->get('referer'),
            ]);

            return $this->accepted($request);
        }

        $leads->capture($request->toData());

        return $this->accepted($request);
    }

    /**
     * Ответ «заявка принята» — один и тот же для человека и для бота.
     *
     * Форму ответа выбирают ЗАГОЛОВКИ запроса (`expectsJson()`), а не поле
     * в форме. Разница принципиальная, и вот почему.
     *
     * `LeadStoreTest` — шестнадцать тестов вехи 3.7 — ходит сюда через
     * `$this->post()` без заголовка `Accept`, а `expectsJson()` при пустом
     * `Accept` и без `X-Requested-With` возвращает `false`. То есть весь
     * этот набор физически не может провалиться в JSON-ветку и остаётся
     * рабочим описанием старого поведения: HTML-путь формы обязан работать
     * при неработающем `app.js`, потому что форма заявки — единственное,
     * ради чего сайт существует.
     *
     * Скрытое поле вида `ajax=1` дало бы ту же функциональность и отобрало
     * бы этого сторожа: контракт ответа начал бы зависеть от разметки,
     * которую как раз и правят, — и первая же правка формы сломала бы
     * ответ, не покраснив ни одним тестом.
     *
     * Ветка применяется и к пути honeypot выше: бот, отправивший форму
     * через `fetch`, обязан получить ровно тот же успех, что и человек.
     * JSON-ветка, которая про honeypot забыла, ловушку обнуляет.
     *
     * Ошибки валидации (422) в этой развилке не участвуют — их по тем же
     * заголовкам отдаёт сам Laravel, и `StoreLeadRequest` для этого трогать
     * не нужно.
     */
    private function accepted(StoreLeadRequest $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::ACCEPTED_MESSAGE]);
        }

        return back()->with('status', self::ACCEPTED_MESSAGE);
    }
}
