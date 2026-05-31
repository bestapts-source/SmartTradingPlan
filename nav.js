// nav.js — общая шапка и навигация
// Подключается на каждой странице: <script src="nav.js"></script>
// Укажи активную страницу: <body data-page="plan">

const NAV_PAGES = [
  { id: 'index',     label: 'Главная',     href: 'index.html' },
  { id: 'plan',      label: 'План',        href: 'plan.html' },
  { id: 'watchlist', label: 'Вотчлист',    href: 'watchlist.html' },
  { id: 'calc',      label: 'Калькулятор', href: 'calc.html' },
  { id: 'journal',   label: 'Дневник',     href: 'journal.html' },
  { id: 'rules',     label: 'Правила',     href: 'rules.html' },
];

document.addEventListener('DOMContentLoaded', () => {
  const activePage = document.body.dataset.page || '';

  // Рендер шапки
  const headerEl = document.getElementById('site-header');
  if (headerEl) {
    headerEl.innerHTML = `
      <div class="header-label">Персональный торговый план · 2026</div>
      <h1>Vadim Shteiman</h1>
      <div class="header-sub">XNES133701 · Свинг-трейдинг · NYSE/NASDAQ из Израиля</div>
      <div class="header-stats">
        <div class="hstat"><span class="hstat-val">2–4</span><span class="hstat-label">сделки в неделю</span></div>
        <div class="hstat"><span class="hstat-val">$800</span><span class="hstat-label">риск на сделку</span></div>
        <div class="hstat"><span class="hstat-val">1:2</span><span class="hstat-label">мин. R:R</span></div>
        <div class="hstat"><span class="hstat-val">$2–5k</span><span class="hstat-label">цель в неделю</span></div>
        <div class="hstat"><span class="hstat-val neg">−21%</span><span class="hstat-label">YTD</span></div>
      </div>
    `;
  }

  // Рендер навигации
  const navEl = document.getElementById('site-nav');
  if (navEl) {
    navEl.innerHTML = NAV_PAGES.map(p =>
      `<a href="${p.href}" class="${p.id === activePage ? 'active' : ''}">${p.label}</a>`
    ).join('');
  }
});
