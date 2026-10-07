{{--
    Согласие на обработку персональных данных.

    Данные готовит `App\Services\ConsentPageContent`: настройка
    `legal.consent` — тело, версия и дата. Blade в базу не ходит.

    Страница существует затем, чтобы согласие было оформлено отдельно от
    политики: на неё ведёт подпись чекбокса в каждой форме заявки. Разметка
    документа общая с политикой — `legal/partials/document.blade.php`.

    `x-lead-section` внизу нет по той же причине, что и на `/privacy`.
--}}
@extends('layouts.app')

@section('title', $title.' — '.config('app.name'))
@section('description', 'Текст согласия на обработку персональных данных, которое посетитель даёт при отправке заявки на сайте ЛАО КАРС: состав данных, цели, срок и порядок отзыва.')

@section('content')
    @include('legal.partials.document')
@endsection
