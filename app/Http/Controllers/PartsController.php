<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\View\View;

/**
 * Посадочная страница подбора запчастей.
 *
 * Заглушка вехи 4.1: заголовок, вводный текст, условия поставки и форма
 * подбора на каркасе. Категории запчастей приходят вехой 4.4.
 *
 * Форма здесь — тот же `x-lead-form`, но с флагом `parts`: он добавляет
 * марку, модель и VIN, по которым `Lead::isPartsRequest()` отличает заявку
 * на подбор от остальных. Флаг легко потерять при правке шаблона, поэтому
 * на него стоит отдельный тест.
 */
final class PartsController extends Controller
{
    public function index(): View
    {
        return view('parts.index', [
            'title' => Setting::get('parts_page.intro_title', 'Запчасти'),
            'intro' => Setting::get('parts_page.intro_text'),
            'deliveryTerms' => Setting::get('parts_page.delivery_terms'),
        ]);
    }
}
