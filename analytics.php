<?php
define('TRADING_BOOT', true);
require __DIR__ . '/api/config.php';
// Phase 5 will wrap this with Auth::requireLogin().
// For now we just need API_KEY to inject into the page so JS can call the API.
$apiKey = defined('API_KEY') ? API_KEY : '';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Аналитика · Trading Plan</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  /* Analytics-specific tweaks layered on top of style.css */

  /* Wider container — KPI cards need breathing room */
  body[data-page="analytics"] main{max-width:1280px;}

  /* Force a clean grid: 4 columns of 2 rows on desktop, 2 on tablet, 1 on mobile */
  .kpi-grid{
    display:grid; gap:10px;
    grid-template-columns:repeat(4, minmax(0,1fr));
  }
  @media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr);}}
  @media(max-width:520px){.kpi-grid{grid-template-columns:1fr;}}
  .kpi{
    background:var(--bg3); border:.5px solid var(--border); border-radius:10px;
    padding:0.9rem 1.1rem;
  }
  .kpi-label{font-size:11px; color:var(--muted);}
  .kpi-val{
    font-family:'DM Mono',monospace; font-size:20px; font-weight:500;
    margin-top:4px; color:var(--text);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }
  .kpi-val.green{color:var(--green);}
  .kpi-val.red  {color:var(--red);}
  .kpi-sub{
    font-family:'DM Mono',monospace; font-size:11px; color:var(--muted); margin-top:3px;
  }
  .kpi-split{display:flex; gap:14px; margin-top:4px;}
  .kpi-split > div{flex:1;}
  .kpi-split-label{font-size:10px; color:var(--muted);}
  .kpi-split-val  {font-family:'DM Mono',monospace; font-size:16px; font-weight:500;}

  .toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:1rem 0 1.5rem;}
  .toolbar select{
    background:var(--bg2); color:var(--text);
    border:.5px solid var(--border); border-radius:8px;
    padding:8px 12px; font-family:'DM Mono',monospace; font-size:12px;
  }
  .toolbar select:focus{outline:none; border-color:var(--gold);}

  .chart-wrap{
    background:var(--bg2); border:.5px solid var(--border);
    border-radius:12px; padding:1rem 1.25rem; min-height:280px; position:relative;
  }
  .chart-wrap canvas{max-height:280px;}

  .badge{
    font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.06em;
    padding:3px 8px; border-radius:4px; text-transform:uppercase; display:inline-block;
  }
  .badge.continue{background:var(--green-dim); color:var(--green);}
  .badge.reduce  {background:var(--amber-dim); color:var(--amber);}
  .badge.cut     {background:var(--red-dim);   color:var(--red);}

  .pl-pos{color:var(--green);}
  .pl-neg{color:var(--red);}
  .num{font-family:'DM Mono',monospace; font-variant-numeric:tabular-nums;}

  .drop-zone{
    margin:1rem 0; padding:2rem;
    border:1.5px dashed var(--border); border-radius:12px;
    text-align:center; color:var(--muted);
    font-family:'DM Mono',monospace; font-size:12px;
    transition:border-color .15s, background .15s; cursor:pointer;
  }
  .drop-zone:hover, .drop-zone.dragover{
    border-color:var(--gold); color:var(--text); background:rgba(195,165,76,0.04);
  }
  .drop-zone input[type=file]{display:none;}

  .upload-status{
    margin-top:10px; font-size:12px; font-family:'DM Mono',monospace;
  }
  .upload-status.error{color:var(--red);}
  .upload-status.ok   {color:var(--green);}

  .meta-row{
    display:flex; flex-wrap:wrap; gap:18px;
    font-family:'DM Mono',monospace; font-size:11px; color:var(--muted);
    padding-bottom:.75rem; border-bottom:.5px solid var(--border); margin-bottom:1rem;
  }
  .meta-row .k{color:var(--gold);}
  .empty{color:var(--muted); font-style:italic; padding:1rem;}

  /* Notes per symbol */
  .notes-grid{display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:10px;}
  .note-card{
    background:var(--bg2); border:.5px solid var(--border); border-radius:10px;
    padding:1rem 1.1rem;
  }
  .note-head{
    display:flex; justify-content:space-between; align-items:baseline;
    margin-bottom:.5rem;
  }
  .note-head .ticker-sym{font-size:14px;}
  .note-head .pl{font-family:'DM Mono',monospace; font-size:12px;}
  .note-row{display:flex; gap:10px; margin-bottom:.5rem; align-items:center; flex-wrap:wrap;}
  .note-row label{font-size:10px; color:var(--muted); font-family:'DM Mono',monospace;
                  text-transform:uppercase; letter-spacing:.06em; min-width:80px;}
  .note-row select, .note-row input[type=text], .note-row textarea{
    flex:1; background:var(--bg3); color:var(--text);
    border:.5px solid var(--border); border-radius:6px;
    padding:6px 8px; font-family:'DM Mono',monospace; font-size:12px;
  }
  .note-row textarea{min-height:50px; resize:vertical; font-family:'DM Sans',sans-serif;}
  .note-row select:focus, .note-row input:focus, .note-row textarea:focus{
    outline:none; border-color:var(--gold);
  }
  .note-flags{display:flex; gap:14px; font-family:'DM Mono',monospace; font-size:11px; color:var(--muted);}
  .note-flags label{display:flex; gap:6px; align-items:center; cursor:pointer;}
  .note-saved{
    font-family:'DM Mono',monospace; font-size:10px; color:var(--green); margin-left:8px;
    opacity:0; transition:opacity .25s;
  }
  .note-saved.show{opacity:1;}

  /* Auto-review (PHP rule-based) */
  .auto-review-card{
    background:var(--bg2); border:.5px solid var(--border); border-radius:12px;
    border-left:2px solid var(--green);
    padding:1.25rem 1.5rem; margin-bottom:10px;
  }
  .auto-review-grid{display:grid; grid-template-columns:1fr 1fr; gap:24px;}
  @media(max-width:760px){.auto-review-grid{grid-template-columns:1fr;}}
  .ar-title{
    font-family:'DM Mono',monospace; font-size:11px; letter-spacing:.06em;
    text-transform:uppercase; color:var(--gold); margin-bottom:10px;
  }
  .ar-bullets{list-style:none; padding:0; margin:0;}
  .ar-bullets li{
    display:flex; gap:10px; align-items:flex-start;
    font-size:13px; color:var(--text); line-height:1.5;
    padding:8px 0; border-bottom:.5px solid rgba(255,255,255,0.04);
  }
  .ar-bullets li:last-child{border-bottom:none;}
  .ar-bullets .ar-icon{flex-shrink:0; font-size:14px; line-height:1.4;}
  .ar-bullets .ar-text{color:var(--muted);}
  .ar-bullets .ar-text strong{color:var(--text); font-weight:500;}
  .ar-hint{
    font-family:'DM Mono',monospace; font-size:11px; color:var(--muted);
    margin-top:14px; padding-top:14px; border-top:.5px dashed rgba(255,255,255,0.05);
    line-height:1.5;
  }

  /* Claude review export */
  .review-card{
    background:var(--bg2); border:.5px solid var(--border); border-radius:12px;
    border-left:2px solid var(--gold);
    padding:1.25rem 1.5rem; margin-bottom:10px;
  }
  .review-text{font-size:13px; color:var(--muted); line-height:1.6; margin-bottom:1rem;}
  .review-text a{color:var(--gold); text-decoration:none;}
  .review-text a:hover{text-decoration:underline;}
  .review-actions{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
  .btn-gold{
    background:var(--gold); color:#0e0f0d; border:none; border-radius:8px;
    padding:9px 18px; font-family:'DM Mono',monospace; font-size:12px; font-weight:500;
    text-transform:uppercase; letter-spacing:.06em; cursor:pointer;
  }
  .btn-gold:hover{filter:brightness(1.1);}
  .btn-secondary{
    background:var(--bg3); color:var(--text);
    border:.5px solid var(--border); border-radius:8px;
    padding:8px 16px; font-family:'DM Mono',monospace; font-size:12px;
    text-decoration:none; text-transform:uppercase; letter-spacing:.06em;
  }
  .btn-secondary:hover{border-color:var(--gold); color:var(--gold);}
  .review-feedback{
    font-family:'DM Mono',monospace; font-size:11px;
    opacity:0; transition:opacity .25s;
  }
  .review-feedback.ok{color:var(--green); opacity:1;}
  .review-feedback.err{color:var(--red);  opacity:1;}
</style>
</head>
<body data-page="analytics">

<header id="site-header"></header>
<nav class="nav" id="site-nav"></nav>

<main>

  <div class="sec-label">Аналитика недели</div>

  <div class="toolbar">
    <label style="font-family:'DM Mono',monospace;font-size:11px;color:var(--muted);">Неделя:</label>
    <select id="weekSelect"></select>
    <span id="metaPeriod" class="num" style="color:var(--gold);margin-left:auto;font-size:12px;"></span>
  </div>

  <!-- BLOCK 1: KPIs -->
  <div class="kpi-grid" id="kpis"></div>

  <!-- BLOCK 2: Equity Curve -->
  <div class="sec-label">Equity curve (закрытый P&L по дням)</div>
  <div class="chart-wrap"><canvas id="equityChart"></canvas></div>

  <!-- BLOCK 4: Open positions with recommendations -->
  <div class="sec-label">Открытые позиции · рекомендации</div>
  <div id="positionsBox"></div>

  <!-- BLOCK 3: Winners / Losers -->
  <div class="sec-label">Символы недели · ранжирование по Realized P&L</div>
  <div id="ranksBox"></div>

  <!-- BLOCK 5: Notes per symbol -->
  <div class="sec-label">Заметки по символам · эмоции, оценка, урок</div>
  <div id="notesBox"></div>

  <!-- BLOCK 6a: Auto-review (rule-based, deterministic) -->
  <div class="sec-label">Алгоритмический разбор</div>
  <div class="auto-review-card">
    <div class="auto-review-grid">
      <div>
        <div class="ar-title">📖 Урок недели</div>
        <ul class="ar-bullets" id="lessonBullets"></ul>
      </div>
      <div>
        <div class="ar-title">🎯 План на следующую неделю</div>
        <ul class="ar-bullets" id="planBullets"></ul>
      </div>
    </div>
    <div class="ar-hint">
      Сгенерировано из данных + правил Recommender. Бесплатно, мгновенно, детерминированно.
      Хочешь разбор «как у Claude» с прозой и вопросами? — кнопка ниже.
    </div>
  </div>

  <!-- BLOCK 6b: Export to Claude for full review -->
  <div class="sec-label">Расширенный разбор от Claude</div>
  <div class="review-card">
    <div class="review-text">
      Соберу все данные этой недели + контекст последних 4 недель + твои заметки в один markdown-пейлоад
      и положу в буфер обмена. Откроешь <a href="https://claude.ai" target="_blank">claude.ai</a>,
      нажмёшь Ctrl+V — получишь развёрнутый разбор как в твоих прошлых сессиях.
    </div>
    <div class="review-actions">
      <button class="btn-gold" id="copyReviewBtn" type="button">📋 Скопировать разбор для Claude</button>
      <a class="btn-secondary" href="https://claude.ai" target="_blank" id="openClaudeBtn">Открыть Claude →</a>
      <span class="review-feedback" id="reviewFeedback"></span>
    </div>
    <details style="margin-top:1rem;">
      <summary style="cursor:pointer; font-family:'DM Mono',monospace; font-size:11px; color:var(--muted);">
        ▸ Показать что будет скопировано
      </summary>
      <pre id="reviewPreview" style="white-space:pre-wrap; background:var(--bg3); padding:12px;
           border-radius:8px; font-family:'DM Mono',monospace; font-size:11px; color:var(--muted);
           max-height:300px; overflow:auto; margin-top:8px;"></pre>
    </details>
  </div>

  <!-- BLOCK 7: Upload -->
  <div class="sec-label">Загрузить отчёт IBKR</div>
  <label class="drop-zone" id="dropZone">
    <input type="file" id="fileInput" accept=".htm,.html">
    <div>Перетащи .htm файл сюда или кликни, чтобы выбрать</div>
    <div style="margin-top:8px;color:rgba(255,255,255,0.25);">Поддерживается U######_YYYYMMDD_YYYYMMDD.htm</div>
  </label>
  <div id="uploadStatus" class="upload-status"></div>

</main>

<script src="nav.js?v=<?= filemtime(__DIR__ . '/nav.js') ?>"></script>
<script>
// ============================================================
// analytics dashboard front-end
// ============================================================

const API_KEY = <?= json_encode($apiKey) ?>;
const API_BASE = 'api/';
let equityChart = null;

const fmt = {
  money: v => v === null || v === undefined ? '—' :
    (v >= 0 ? '+' : '') + Number(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}),
  // Full money without leading sign (used for NAV totals — "$107,022.51")
  moneyFull: v => v === null || v === undefined ? '—' :
    Number(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}),
  moneyShort: v => v === null || v === undefined ? '—' :
    Number(v).toLocaleString('en-US',{maximumFractionDigits:0}),
  pct: v => v === null || v === undefined ? '—' : Number(v).toFixed(2) + '%',
  date: s => {
    if (!s) return '';
    try {
      // s is UTC datetime "YYYY-MM-DD HH:MM:SS" or date "YYYY-MM-DD"
      const iso = s.length === 10 ? s + 'T00:00:00Z' : s.replace(' ','T') + 'Z';
      return new Date(iso).toLocaleString('ru-IL',{
        timeZone:'Asia/Jerusalem',
        year:'numeric', month:'2-digit', day:'2-digit',
        hour:'2-digit', minute:'2-digit'
      });
    } catch(e) { return s; }
  },
  dateOnly: s => {
    if (!s) return '';
    try {
      const iso = s + 'T12:00:00Z';
      return new Date(iso).toLocaleDateString('ru-IL',{day:'2-digit',month:'short'});
    } catch(e) { return s; }
  }
};

function plClass(v) {
  if (v === null || v === undefined || Number(v) === 0) return '';
  return Number(v) > 0 ? 'pl-pos' : 'pl-neg';
}

// ---- API ---------------------------------------------------
async function api(action, params = {}) {
  const q = new URLSearchParams({ api_key: API_KEY, action, ...params });
  const r = await fetch(`${API_BASE}analytics.php?${q}`);
  const j = await r.json();
  if (!j.success) throw new Error(j.error || 'API error');
  return j;
}

// ---- Renderers ---------------------------------------------
function renderKpis(data) {
  const imp   = data.import;
  const stats = data.stats;
  const nv = n => Number(n);
  // KPI plan: row 1 = NAV start, NAV end, Δ, TWR
  //          row 2 = Realized, Win rate, Сделок, Best/Worst
  const cards = [
    {
      label: 'NAV начало',
      html: `<div class="kpi-val">$${fmt.moneyFull(imp.nav_start)}</div>`,
    },
    {
      label: 'NAV конец',
      html: `<div class="kpi-val">$${fmt.moneyFull(imp.nav_end)}</div>`,
    },
    {
      label: 'Изменение',
      html: `<div class="kpi-val ${nv(imp.nav_change) >= 0 ? 'green' : 'red'}">${fmt.money(imp.nav_change)}</div>`,
    },
    {
      label: 'TWR',
      html: `<div class="kpi-val ${nv(imp.twr) >= 0 ? 'green' : 'red'}">${fmt.pct(imp.twr)}</div>`,
    },
    {
      label: 'Realized P&L',
      html: `<div class="kpi-val ${nv(stats.total_realized) >= 0 ? 'green' : 'red'}">${fmt.money(stats.total_realized)}</div>
             <div class="kpi-sub">${stats.closing_trades} закр., ср. ${stats.avg_pl===null?'—':fmt.money(stats.avg_pl)}</div>`,
    },
    {
      label: 'Win rate',
      html: `<div class="kpi-val ${nv(stats.win_rate) >= 50 ? 'green' : 'red'}">${stats.win_rate===null?'—':fmt.pct(stats.win_rate)}</div>
             <div class="kpi-sub">${stats.winners} побед · ${stats.losers} убытков</div>`,
    },
    {
      label: 'Всего сделок',
      html: `<div class="kpi-val">${stats.total_trades}</div>
             <div class="kpi-sub">из них ${stats.closing_trades} закрывающих</div>`,
    },
    {
      label: 'Best / Worst',
      html: `<div class="kpi-split">
               <div><div class="kpi-split-label">Лучшая</div><div class="kpi-split-val green">${fmt.money(stats.best_trade)}</div></div>
               <div><div class="kpi-split-label">Худшая</div><div class="kpi-split-val red">${fmt.money(stats.worst_trade)}</div></div>
             </div>`,
    },
  ];
  document.getElementById('kpis').innerHTML = cards.map(c => `
    <div class="kpi">
      <div class="kpi-label">${c.label}</div>
      ${c.html}
    </div>
  `).join('');

  document.getElementById('metaPeriod').textContent =
    `${imp.period_start} → ${imp.period_end} · ${imp.account_id}`;
}

function renderEquityCurve(data) {
  const pts = data.equity_curve.points;
  const labels = pts.map(p => fmt.dateOnly(p.date));
  const cum    = pts.map(p => p.realized_cum);
  const ctx = document.getElementById('equityChart').getContext('2d');
  if (equityChart) equityChart.destroy();
  equityChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Cum. realized P&L',
        data: cum,
        borderColor: '#3dd68c',
        backgroundColor: 'rgba(61,214,140,0.10)',
        fill: true,
        tension: 0.25,
        pointRadius: 4,
        pointBackgroundColor: '#3dd68c',
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#7a786e', font: { family: 'DM Mono', size: 11 } } },
        tooltip: { callbacks: { label: c => '$' + fmt.money(c.parsed.y) } }
      },
      scales: {
        x: { ticks: { color: '#7a786e', font: { family: 'DM Mono', size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } },
        y: { ticks: { color: '#7a786e', font: { family: 'DM Mono', size: 10 }, callback: v => '$' + fmt.moneyShort(v) },
             grid: { color: 'rgba(255,255,255,0.04)' } }
      }
    }
  });
}

function renderPositions(data) {
  const pos = data.open_positions || [];
  if (pos.length === 0) {
    document.getElementById('positionsBox').innerHTML =
      '<div class="empty">Нет открытых позиций.</div>';
    return;
  }
  const rows = pos.map(p => {
    const rec = p.recommendation || {};
    const badgeCls = rec.action === 'CONTINUE' ? 'continue' : rec.action === 'REDUCE' ? 'reduce' : 'cut';
    const u = Number(p.unrealized_pl);
    return `
      <tr>
        <td><span class="ticker-sym">${p.symbol}</span></td>
        <td class="num">${Number(p.quantity).toLocaleString('en-US')}</td>
        <td class="num">$${Number(p.avg_cost).toFixed(2)}</td>
        <td class="num">$${Number(p.close_price).toFixed(2)}</td>
        <td class="num">$${fmt.moneyShort(p.market_value)}</td>
        <td class="num ${plClass(u)}">${fmt.money(u)}</td>
        <td class="num ${plClass(rec.pct)}">${rec.pct === undefined ? '—' : rec.pct + '%'}</td>
        <td>
          <span class="badge ${badgeCls}">${rec.action || '—'}</span>
          <div style="font-size:10px;color:var(--muted);margin-top:3px;font-family:'DM Mono',monospace;">${rec.reason || ''}</div>
        </td>
      </tr>`;
  }).join('');
  document.getElementById('positionsBox').innerHTML = `
    <table class="wtable">
      <thead><tr>
        <th>Символ</th><th>Qty</th><th>Avg cost</th><th>Close</th>
        <th>Market value</th><th>Unrealized</th><th>%</th><th>Действие</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

function renderRanks(data) {
  const sym = (data.rank_symbols || [])
    .filter(s => s.realized_pl !== null || s.unrealized_pl !== null)
    .sort((a,b) => Number(b.realized_pl ?? 0) - Number(a.realized_pl ?? 0));
  if (sym.length === 0) {
    document.getElementById('ranksBox').innerHTML = '<div class="empty">Нет данных по символам.</div>';
    return;
  }
  const rows = sym.map(s => `
    <tr>
      <td><span class="ticker-sym">${s.symbol}</span>
          <div style="font-size:10px;color:var(--muted);font-family:'DM Mono',monospace;">${s.description || ''}</div></td>
      <td class="num">${s.trade_count || 0}</td>
      <td class="num ${plClass(s.realized_pl)}">${fmt.money(s.realized_pl)}</td>
      <td class="num ${plClass(s.unrealized_pl)}">${fmt.money(s.unrealized_pl)}</td>
      <td class="num ${plClass(s.total_pl)}">${fmt.money(s.total_pl)}</td>
      <td class="num ${plClass(s.ytd_pl)}">${fmt.money(s.ytd_pl)}</td>
    </tr>
  `).join('');
  document.getElementById('ranksBox').innerHTML = `
    <table class="wtable">
      <thead><tr>
        <th>Символ</th><th>Сделок</th><th>Realized</th><th>Unrealized</th><th>Total (нед.)</th><th>YTD</th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

// ---- BLOCK 5: notes per symbol ----------------------------------
const EMOTIONS_BEFORE = ['Calm','Confident','FOMO','Anxious','Greedy','Neutral'];
const EMOTIONS_AFTER  = ['Satisfied','Regret','Neutral','Proud','Frustrated'];

function renderNotes(data) {
  const trades   = data.trades || [];
  const symbols  = (data.rank_symbols || [])
    .filter(s => s.symbol && s.realized_pl !== null);
  if (symbols.length === 0) {
    document.getElementById('notesBox').innerHTML =
      '<div class="empty">Нет торгуемых символов на этой неделе.</div>';
    return;
  }
  const notes   = data.notes || {};
  const importId= data.import.id;
  const optHtml = (list, current) => '<option value="">—</option>' +
    list.map(v => `<option value="${v}" ${v===current?'selected':''}>${v}</option>`).join('');

  document.getElementById('notesBox').innerHTML = `<div class="notes-grid">
    ${symbols.map(s => {
      const n = notes[s.symbol] || {};
      const pl = Number(s.realized_pl || 0);
      const plClass = pl > 0 ? 'pl-pos' : pl < 0 ? 'pl-neg' : '';
      return `
      <div class="note-card" data-symbol="${s.symbol}">
        <div class="note-head">
          <span class="ticker-sym">${s.symbol}</span>
          <span class="pl ${plClass}">${fmt.money(pl)}</span>
        </div>
        <div class="note-row">
          <label>До</label>
          <select data-field="emotion_before">${optHtml(EMOTIONS_BEFORE, n.emotion_before)}</select>
        </div>
        <div class="note-row">
          <label>После</label>
          <select data-field="emotion_after">${optHtml(EMOTIONS_AFTER, n.emotion_after)}</select>
        </div>
        <div class="note-flags">
          <label><input type="checkbox" data-field="good_trade" ${n.good_trade==1?'checked':''}> Хорошая сделка</label>
          <label><input type="checkbox" data-field="regret_flag" ${n.regret_flag==1?'checked':''}> Regret</label>
        </div>
        <div class="note-row" style="margin-top:.5rem;">
          <label>Урок</label>
          <textarea data-field="lesson" placeholder="Что сделал бы иначе?">${n.lesson || ''}</textarea>
        </div>
        <div class="note-row">
          <label>Заметка</label>
          <textarea data-field="free_text" placeholder="Свободный текст…">${n.free_text || ''}</textarea>
        </div>
        <div class="note-saved" data-saved>сохранено ✓</div>
      </div>`;
    }).join('')}
  </div>`;

  // Wire up auto-save on change
  document.querySelectorAll('#notesBox .note-card').forEach(card => {
    const symbol = card.dataset.symbol;
    let debounce = null;
    const collect = () => {
      const payload = { import_id: importId, symbol };
      card.querySelectorAll('[data-field]').forEach(el => {
        const k = el.dataset.field;
        if (el.type === 'checkbox') payload[k] = el.checked ? 1 : 0;
        else                        payload[k] = el.value || null;
      });
      return payload;
    };
    const save = async () => {
      try {
        await fetch(`${API_BASE}analytics.php?action=save_note&api_key=${encodeURIComponent(API_KEY)}`, {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify(collect())
        }).then(r => r.json()).then(j => {
          if (!j.success) throw new Error(j.error || 'save failed');
        });
        const flag = card.querySelector('[data-saved]');
        flag.classList.add('show');
        setTimeout(() => flag.classList.remove('show'), 1200);
      } catch (e) {
        const flag = card.querySelector('[data-saved]');
        flag.textContent = '✗ ' + e.message;
        flag.style.color = 'var(--red)';
        flag.classList.add('show');
      }
    };
    card.querySelectorAll('[data-field]').forEach(el => {
      el.addEventListener('change', () => { clearTimeout(debounce); debounce = setTimeout(save, 250); });
      if (el.tagName === 'TEXTAREA') {
        el.addEventListener('blur', save);
      }
    });
  });
}

function renderWeekSelect(imports, currentId) {
  const sel = document.getElementById('weekSelect');
  sel.innerHTML = imports.map(i =>
    `<option value="${i.id}" ${i.id == currentId ? 'selected' : ''}>${i.period_start} → ${i.period_end} (${fmt.pct(i.twr)})</option>`
  ).join('');
  sel.onchange = () => loadWeek(sel.value);
}

// ---- BLOCK 6a: rule-based auto-review ------------------------
function renderAutoReview(payload) {
  const lessonUl = document.getElementById('lessonBullets');
  const planUl   = document.getElementById('planBullets');
  const renderList = (ul, items) => {
    if (!items || items.length === 0) {
      ul.innerHTML = '<li><span class="ar-icon">·</span><span class="ar-text">Недостаточно данных для выводов.</span></li>';
      return;
    }
    ul.innerHTML = items.map(b => {
      // Convert **bold** markers to <strong> safely
      const html = escapeHtml(b.text).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      return `<li><span class="ar-icon">${b.icon || '·'}</span><span class="ar-text">${html}</span></li>`;
    }).join('');
  };
  renderList(lessonUl, payload.lesson_bullets || []);
  renderList(planUl,   payload.plan_bullets   || []);
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function loadAutoReview(importId) {
  try {
    const r = await api('auto_review', importId ? { import_id: importId } : {});
    renderAutoReview(r);
  } catch (e) {
    document.getElementById('lessonBullets').innerHTML =
      `<li><span class="ar-icon">✗</span><span class="ar-text">Ошибка: ${escapeHtml(e.message)}</span></li>`;
  }
}

// ---- BLOCK 6b: Claude review payload --------------------------
let lastWeekData = null;
let allImports   = [];

function buildReviewPayload(week, imports) {
  const imp     = week.import;
  const st      = week.stats;
  const trades  = week.trades || [];
  const pos     = week.open_positions || [];
  const ranks   = (week.rank_symbols || [])
                   .filter(s => s.realized_pl != null || s.unrealized_pl != null);
  const notes   = week.notes || {};
  const eqPts   = (week.equity_curve && week.equity_curve.points) || [];

  // Helper
  const m = v => v == null ? '—' : (v >= 0 ? '+' : '') + Number(v).toFixed(2);
  const r = v => v == null ? '—' : Number(v).toFixed(2);
  const ilDate = s => s ? new Date(s.replace(' ','T') + 'Z').toLocaleString('he-IL',
                          {timeZone:'Asia/Jerusalem', year:'2-digit', month:'2-digit', day:'2-digit',
                           hour:'2-digit', minute:'2-digit'}) : '';

  // Previous weeks context — pull from imports list, exclude current
  const prevWeeks = imports
    .filter(i => i.id !== imp.id)
    .slice(0, 4)
    .map(i => `- ${i.period_start} → ${i.period_end}: NAV ${m(i.nav_change)}, TWR ${i.twr}%`)
    .join('\n');

  // Trades table
  const tradeLines = trades.map(t => {
    const dt = ilDate(t.trade_datetime);
    const qty = Number(t.quantity);
    const side = qty > 0 ? 'BUY' : 'SELL';
    const code = t.trade_code ? ` [${t.trade_code}]` : '';
    return `- ${t.symbol} | ${dt} | ${side} ${Math.abs(qty)} @ $${r(t.trade_price)} | proceeds ${m(t.proceeds)} | realized ${m(t.realized_pl)} | MTM ${m(t.mtm_pl)}${code}`;
  }).join('\n');

  // Open positions
  const posLines = pos.map(p => {
    const u = Number(p.unrealized_pl);
    const pct = (u / (Math.abs(p.quantity) * p.avg_cost) * 100).toFixed(2);
    const note = notes[p.symbol];
    const noteStr = note ? ` | note: ${note.lesson || note.free_text || ''}`.slice(0, 80) : '';
    const rec = p.recommendation || {};
    return `- ${p.symbol}: ${p.quantity} @ avg $${r(p.avg_cost)} → close $${r(p.close_price)}, unrealized ${m(p.unrealized_pl)} (${pct}%), action: ${rec.action || '—'}${noteStr}`;
  }).join('\n');

  // Symbol ranking (top winners + losers + holdings)
  const ranked = ranks.slice(0, 15).map(s => {
    const realized = Number(s.realized_pl || 0);
    const ytd = Number(s.ytd_pl || 0);
    return `- ${s.symbol} (${s.asset_class || 'Stock'}): week realized ${m(s.realized_pl)}, unrealized ${m(s.unrealized_pl)}, week total ${m(s.total_pl)}, YTD ${m(s.ytd_pl)} (${s.trade_count || 0} trades)`;
  }).join('\n');

  // Notes
  const noteLines = Object.values(notes).map(n => {
    const tags = [];
    if (n.good_trade == 1)  tags.push('GOOD');
    if (n.regret_flag == 1) tags.push('REGRET');
    const meta = [];
    if (n.emotion_before) meta.push(`before=${n.emotion_before}`);
    if (n.emotion_after)  meta.push(`after=${n.emotion_after}`);
    if (n.setup)          meta.push(`setup="${n.setup}"`);
    return `- ${n.symbol}${tags.length ? ' [' + tags.join(',') + ']' : ''} ${meta.join(' ')}\n  lesson: ${n.lesson || '—'}\n  text: ${(n.free_text || '—').replace(/\n/g, ' | ')}`;
  }).join('\n');

  // Equity curve per day
  const eqLines = eqPts.map(p =>
    `- ${p.date}: day ${m(p.realized_day)}, cum ${m(p.realized_cum)}`
  ).join('\n');

  return `# Разбор торговой недели

## Контекст трейдера
Vadim Shteiman · IBKR ${imp.account_id} · свинг-трейдинг NYSE/NASDAQ из Израиля
- Капитал ~$105k · риск $800/сделку · мин. R:R 1:2
- Цель $2-5k/неделю · 2-4 сделки/неделю
- YTD на минимумах — идёт восстановление

## Эта неделя: ${imp.period_start} → ${imp.period_end}

### KPI
- NAV: $${r(imp.nav_start)} → $${r(imp.nav_end)} (${m(imp.nav_change)})
- TWR: ${imp.twr}%
- Realized: ${m(st.total_realized)}, Avg/trade: ${m(st.avg_pl)}
- Win rate: ${st.win_rate}% (${st.winners}W / ${st.losers}L из ${st.closing_trades} закрытых)
- Best: ${m(st.best_trade)}, Worst: ${m(st.worst_trade)}
- Commissions: ${m(imp.commissions)}, Dividends: ${m(imp.dividends)}, Interest: ${m(imp.interest)}

### Equity curve по дням
${eqLines || '(нет данных)'}

### Сделки (${trades.length})
${tradeLines || '(нет сделок)'}

### Открытые позиции на конец недели (${pos.length})
${posLines || '(нет открытых позиций)'}

Алгоритмическая рекомендация (rule-based из дашборда):
- CONTINUE если unrealized > +5% и нет regret
- REDUCE если unrealized между -3% и +5%
- CUT если unrealized < -3% или regret_flag=1

### Ранжирование по символам (top 15)
${ranked || '(нет данных)'}

### Заметки трейдера за неделю (эмоции + уроки)
${noteLines || '(заметок нет)'}

## Контекст последних недель
${prevWeeks || '(нет)'}

---

## Что нужно от тебя как от аналитика:

1. **Разбор недели**: что сработало, что нет — конкретно по тикерам, с цифрами.
2. **Эмоциональные паттерны**: посмотри на emotion_before/after vs result. Где FOMO/Greedy дали результат, где Calm/Confident.
3. **Открытые позиции**: для каждой — где ставить стоп, фиксировать ли часть, или держать. Объясни логику.
4. **План на следующую неделю**: focus tickers (продолжать), avoid (избегать), на что особенно обратить внимание.
5. **Если чего-то не хватает в данных или нужно уточнение** — задай вопрос, не выдумывай.

Отвечай как опытный аналитик: конкретно, без воды, с цифрами. Можно использовать таблицы и markdown.
`;
}

async function copyReview() {
  const fb = document.getElementById('reviewFeedback');
  if (!lastWeekData) {
    fb.className = 'review-feedback err';
    fb.textContent = 'нет данных — подожди загрузки';
    return;
  }
  const payload = buildReviewPayload(lastWeekData, allImports);
  document.getElementById('reviewPreview').textContent = payload;
  try {
    await navigator.clipboard.writeText(payload);
    fb.className = 'review-feedback ok';
    fb.textContent = `✓ скопировано (${payload.length} символов) — открой Claude и вставь Ctrl+V`;
  } catch (e) {
    fb.className = 'review-feedback err';
    fb.textContent = '✗ ошибка копирования: ' + e.message;
  }
}

// ---- Orchestrator ------------------------------------------
async function loadWeek(importId) {
  try {
    const list = await api('imports');
    if (list.imports.length === 0) {
      document.querySelector('main').innerHTML +=
        '<div class="empty">Пока нет загруженных отчётов. Залей .htm файл ниже ↓</div>';
      return;
    }
    const week = await api('weekly', importId ? { import_id: importId } : {});
    lastWeekData = week;
    allImports   = list.imports;
    renderWeekSelect(list.imports, week.import.id);
    renderKpis(week);
    renderEquityCurve(week);
    renderPositions(week);
    renderRanks(week);
    renderNotes(week);
    loadAutoReview(week.import.id);
    // Pre-fill the preview pane so user can see what'll be copied
    document.getElementById('reviewPreview').textContent = buildReviewPayload(week, list.imports);
  } catch (e) {
    document.getElementById('kpis').innerHTML =
      `<div class="empty pl-neg">Ошибка загрузки: ${e.message}</div>`;
  }
}

// ---- Upload ------------------------------------------------
function setupUpload() {
  const dz   = document.getElementById('dropZone');
  const fi   = document.getElementById('fileInput');
  const stat = document.getElementById('uploadStatus');

  const upload = async (file) => {
    if (!file) return;
    stat.className = 'upload-status';
    stat.textContent = `Загружаю ${file.name}…`;
    const fd = new FormData();
    fd.append('report', file);
    try {
      const r = await fetch(`${API_BASE}import.php?api_key=${encodeURIComponent(API_KEY)}`, {
        method: 'POST', body: fd
      });
      const j = await r.json();
      if (!j.success) throw new Error(j.error || 'Ошибка импорта');
      stat.className = 'upload-status ok';
      stat.textContent = `✓ Импорт #${j.import_id}: ${j.trades} сделок, ${j.positions} позиций, ${j.summaries} символов. NAV ${fmt.money(j.nav_change)} · TWR ${fmt.pct(j.twr)}`;
      await loadWeek(j.import_id);
    } catch (e) {
      stat.className = 'upload-status error';
      stat.textContent = '✗ ' + e.message;
    }
  };

  fi.onchange = () => upload(fi.files[0]);
  ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault(); dz.classList.add('dragover');
  }));
  ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault(); dz.classList.remove('dragover');
  }));
  dz.addEventListener('drop', e => upload(e.dataTransfer.files[0]));
}

// ---- Bootstrap ---------------------------------------------
loadWeek();
setupUpload();
document.getElementById('copyReviewBtn').addEventListener('click', copyReview);
</script>
</body>
</html>
