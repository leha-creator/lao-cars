<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Приём заявок со всех форм сайта (веха 3.7).
 *
 * Контроллер тонкий по правилу `ARCHITECTURE.md`: валидация — в
 * `StoreLeadRequest`, запись и уведомление — в `LeadService`. Именно из
 * толстых контроллеров теряются заявки.
 */
final class LeadController extends Controller
{
    public function store(StoreLeadRequest $request, LeadService $leads): RedirectResponse
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

            return $this->accepted();
        }

        $leads->capture($request->toData());

        return $this->accepted();
    }

    /**
     * Ответ «заявка принята» — один и тот же для человека и для бота.
     */
    private function accepted(): RedirectResponse
    {
        return back()->with('status', 'Заявка принята — менеджер свяжется с вами.');
    }
}
