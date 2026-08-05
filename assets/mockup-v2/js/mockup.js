/*
 * Скрипты макета. Один файл на обе страницы, поэтому каждый блок сначала
 * проверяет, что его разметка на странице есть, и молча выходит, если нет.
 *
 * Проверка обязательна не из аккуратности: без неё обращение к отсутствующему
 * элементу бросает TypeError, исполнение файла обрывается на этом месте —
 * и всё, что объявлено НИЖЕ, не работает вовсе. На странице услуг нет
 * каталога и подбора, зато есть меню и форма; без сторожей они бы отвалились
 * без единого видимого признака, кроме молчащих кликов.
 */

// Мобильное меню.
(function () {
  var burger = document.getElementById('burger');
  var menu = document.getElementById('mobileMenu');
  var close = document.getElementById('menuClose');
  if (!burger || !menu || !close) return;

  function setOpen(open) {
    menu.dataset.open = open ? 'true' : 'false';
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.style.overflow = open ? 'hidden' : '';
  }

  burger.addEventListener('click', function () { setOpen(true); });
  close.addEventListener('click', function () { setOpen(false); });
  menu.querySelectorAll('a').forEach(function (a) {
    a.addEventListener('click', function () { setOpen(false); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
})();

// Фильтр каталога по статусу. На рабочем сайте это обычные GET-параметры
// (списки каталога должны индексироваться); здесь — показ/скрытие карточек.
(function () {
  var grid = document.getElementById('carsGrid');
  if (!grid) return;

  var filters = document.querySelectorAll('.filter');
  var cards = grid.querySelectorAll('[data-status]');

  filters.forEach(function (button) {
    button.addEventListener('click', function () {
      filters.forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
      button.setAttribute('aria-pressed', 'true');

      var filter = button.dataset.filter;
      cards.forEach(function (card) {
        var show = filter === 'all' || card.dataset.status === filter;
        card.style.display = show ? '' : 'none';
      });
    });
  });
})();

// Быстрый подбор: пересчёт подписи бюджета и числа направлений.
(function () {
  var budget = document.getElementById('budget');
  var output = document.getElementById('budgetOutput');
  var count = document.getElementById('matchCount');
  var text = document.getElementById('matchText');
  if (!budget || !output || !count || !text) return;

  function formatRub(value) {
    return (value / 1000000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + ' млн ₽';
  }

  function update() {
    var value = Number(budget.value);
    output.textContent = formatRub(value);

    var engine = document.querySelector('input[name="engine"]:checked').value;
    var term = document.querySelector('input[name="term"]:checked').value;

    var matches = value < 3000000 ? 2 : value < 5000000 ? 4 : value < 8000000 ? 6 : 8;
    if (engine === 'Электро') matches = Math.max(2, matches - 1);
    if (term === 'Сейчас') matches = Math.max(2, matches - 1);

    count.textContent = matches;
    text.textContent = engine + ', срок «' + term.toLowerCase() + '»: ориентировочно ' +
      matches + ' направлений для дальнейшего подбора. Точное наличие и условия подтверждает менеджер.';
  }

  budget.addEventListener('input', update);
  document.querySelectorAll('input[name="engine"], input[name="term"]').forEach(function (input) {
    input.addEventListener('change', update);
  });
  update();
})();

/*
 * Выбор конкретной позиции прайса подставляется в форму заявки.
 *
 * Это не украшение: на рабочем сайте у заявки полиморфный источник
 * (`Lead::source` на `Service`), и запись «на сервис вообще» вместо
 * «на полировку кузова» теряет ровно то, ради чего человек кликнул.
 * Здесь тот же смысл выражен полем «Интересует».
 */
(function () {
  var rows = document.querySelectorAll('[data-service]');
  var select = document.querySelector('#leadForm select[name="interest"]');
  if (!rows.length || !select) return;

  rows.forEach(function (row) {
    row.addEventListener('click', function () {
      var title = row.dataset.service;
      var match = [...select.options].find(function (option) { return option.value === title; });

      if (!match) {
        match = new Option(title, title);
        select.add(match);
      }

      select.value = title;
    });
  });
})();

// Форма: макет ничего не отправляет, показывает состояние успеха.
(function () {
  var form = document.getElementById('leadForm');
  var success = document.getElementById('leadSuccess');
  if (!form || !success) return;

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    success.dataset.visible = 'true';

    var button = form.querySelector('button[type="submit"]');
    button.textContent = 'Заявка отправлена';
    button.disabled = true;
    button.style.opacity = '.6';
  });
})();
