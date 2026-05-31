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

function renderWeekSelect(imports, currentId) {
  const sel = document.getElementById('weekSelect');
  sel.innerHTML = imports.map(i =>
    `<option value="${i.id}" ${i.id == currentId ? 'selected' : ''}>${i.period_start} → ${i.period_end} (${fmt.pct(i.twr)})</option>`
  ).join('');
  sel.onchange = () => loadWeek(sel.value);
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
    renderWeekSelect(list.imports, week.import.id);
    renderKpis(week);
    renderEquityCurve(week);
    renderPositions(week);
    renderRanks(week);
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
</script>
</body>
</html>
