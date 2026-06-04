<?php
define('TRADING_BOOT', true);
require __DIR__ . '/api/config.php';
$apiKey = defined('API_KEY') ? API_KEY : '';
?>
<!DOCTYPE html>
<!-- МОДУЛЬ: Калькулятор · Редактировать ТОЛЬКО этот файл -->
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Калькулятор · Vadim Shteiman</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
.quick-row{display:grid;grid-template-columns:90px 110px 110px 110px;gap:8px;align-items:end;margin-bottom:12px;}
@media(max-width:600px){.quick-row{grid-template-columns:1fr 1fr;}}
.quick-row label{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;letter-spacing:.06em;text-transform:uppercase;display:block;margin-bottom:4px;}
.quick-row input{background:var(--bg3);border:.5px solid var(--border);border-radius:8px;padding:10px 12px;color:var(--text);font-family:'DM Mono',monospace;font-size:15px;font-weight:500;width:100%;outline:none;transition:border-color .2s;}
.quick-row input:focus{border-color:rgba(195,165,76,0.5);}
.quick-row input::placeholder{font-size:12px;font-weight:400;}

.calc-result{border-radius:10px;padding:1.25rem 1.5rem;margin-top:14px;font-family:'DM Mono',monospace;font-size:13px;line-height:2.2;display:none;}
.calc-result.ready{background:var(--green-dim);border:.5px solid rgba(61,214,140,0.2);color:var(--green);}
.calc-result strong{color:var(--text);}
.calc-result .big{font-size:24px;font-weight:500;}
.calc-result .warn{color:var(--amber);}
.calc-result .err{color:var(--red);}

.calc-reset{background:none;border:none;color:var(--muted);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;padding:0;margin-left:12px;text-decoration:underline;text-underline-offset:2px;}
.calc-reset:hover{color:var(--text);}

/* 6 правил — встроенный pre-trade чеклист */
.rules-card{
  margin-top:16px; padding:16px 18px;
  background:var(--bg2); border:.5px solid var(--border); border-radius:10px;
}
.rules-verdict{
  display:flex; justify-content:space-between; align-items:center;
  padding:8px 14px; border-radius:8px;
  font-family:'DM Mono',monospace; font-size:13px; font-weight:500;
  margin-bottom:14px;
}
.rules-verdict.ok      {background:var(--green-dim); color:var(--green);}
.rules-verdict.caution {background:var(--amber-dim); color:var(--amber);}
.rules-verdict.check   {background:var(--blue-dim);  color:var(--blue);}
.rules-verdict.no      {background:var(--red-dim);   color:var(--red);}
.rules-verdict-counts{font-size:11px; opacity:.8; font-weight:400;}
.rules-list{list-style:none; padding:0; margin:0;}
.rules-list li{
  display:flex; gap:10px; align-items:flex-start;
  padding:8px 0; border-bottom:.5px solid rgba(255,255,255,0.04);
  font-size:12px;
}
.rules-list li:last-child{border-bottom:none;}
.rule-icon{flex-shrink:0; width:22px; font-family:'DM Mono',monospace;}
.rule-icon.ok    {color:var(--green);}
.rule-icon.fail  {color:var(--red);}
.rule-icon.warn  {color:var(--amber);}
.rule-icon.manual{color:var(--muted);}
.rule-title{color:var(--text); font-weight:500; font-family:'DM Mono',monospace; font-size:11px; letter-spacing:.04em; text-transform:uppercase;}
.rule-reason{color:var(--muted); font-size:12px; margin-top:2px; line-height:1.5;}

/* История */
#calcLog{margin-top:12px;display:flex;flex-direction:column;gap:8px;}
.calc-entry{background:var(--bg3);border:.5px solid var(--border);border-radius:10px;padding:12px 16px;font-size:13px;}
.calc-entry-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;}
.calc-ticker{font-family:'DM Mono',monospace;font-weight:500;color:var(--text);font-size:15px;}
.calc-nums{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);margin-top:5px;line-height:1.9;border-top:.5px solid var(--border);padding-top:6px;}
.calc-nums .g{color:var(--green);}
.calc-nums .r{color:var(--red);}
.api-status{font-family:'DM Mono',monospace;font-size:11px;color:var(--muted);margin-left:auto;}

.btn{background:var(--gold);color:#0e0f0d;border:none;border-radius:8px;padding:9px 18px;font-family:'DM Mono',monospace;font-size:12px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;}
.btn:hover{filter:brightness(1.1);}
.btn.sm{padding:6px 12px;font-size:10px;}
.btn.secondary{background:var(--bg3);color:var(--text);border:.5px solid var(--border);}
.btn.danger{background:transparent;color:var(--red);border:.5px solid var(--red);}
</style>
</head>
<body data-page="calc">

<header id="site-header"></header>
<nav class="nav" id="site-nav"></nav>

<main>
  <div class="sec-label">Быстрый расчёт</div>
  <div class="card blue">
    <span class="tag blue">Tab между полями — результат мгновенно</span>
    <div class="card-title">Вход · Стоп · Цель</div>

    <div style="margin-top:14px;" class="jform">
      <div class="quick-row">
        <div>
          <label>Тикер</label>
          <input type="text" id="c-ticker" placeholder="RKLB" oninput="calcPos()" style="text-transform:uppercase">
        </div>
        <div>
          <label>Вход ($)</label>
          <input type="number" id="c-entry" placeholder="75.00" step="0.01" oninput="calcPos()" onkeydown="tabNext(event,'c-stop')">
        </div>
        <div>
          <label>Стоп ($)</label>
          <input type="number" id="c-stop" placeholder="72.50" step="0.01" oninput="calcPos()" onkeydown="tabNext(event,'c-target')">
        </div>
        <div>
          <label>Цель ($)</label>
          <input type="number" id="c-target" placeholder="80.00" step="0.01" oninput="calcPos()">
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:6px;">
        <div style="flex:1;min-width:140px;">
          <label>Капитал ($)</label>
          <input type="number" id="c-capital" value="104927" oninput="calcPos()" style="background:var(--bg3);border:.5px solid var(--border);border-radius:8px;padding:10px 12px;color:var(--text);font-family:'DM Mono',monospace;font-size:14px;width:100%;outline:none;">
        </div>
        <div style="flex:1;min-width:120px;">
          <label>Риск (%)</label>
          <input type="number" id="c-risk-pct" value="0.75" step="0.05" oninput="calcPos()" style="background:var(--bg3);border:.5px solid var(--border);border-radius:8px;padding:10px 12px;color:var(--text);font-family:'DM Mono',monospace;font-size:14px;width:100%;outline:none;">
        </div>
        <div style="display:flex;gap:8px;align-items:center;padding-bottom:2px;">
          <button class="btn sm" id="save-btn" onclick="saveCalc()" style="display:none;">Сохранить</button>
          <button class="calc-reset" onclick="resetCalc()">Сбросить</button>
        </div>
      </div>

      <div id="calc-result" class="calc-result"></div>

      <!-- 6-rule pre-trade check -->
      <div id="rules-card" class="rules-card" style="display:none;">
        <div id="rules-verdict" class="rules-verdict"></div>
        <ul id="rules-list" class="rules-list"></ul>
      </div>
    </div>
  </div>

  <div class="sec-label">
    История расчётов
    <span class="api-status" id="api-status">Загружаю…</span>
  </div>
  <div id="calcLog"></div>

  <div class="sec-label">Формула</div>
  <div class="formula">
    Риск в $ = <strong>Капитал × % риска</strong><br>
    Лот = <strong>Риск $ ÷ (Вход − Стоп)</strong><br>
    Цель 2R = <strong>Вход + (Вход − Стоп) × 2</strong><br>
    Цель 3R = <strong>Вход + (Вход − Стоп) × 3</strong>
  </div>
</main>

<script src="nav.js?v=<?= filemtime(__DIR__ . '/nav.js') ?>"></script>
<script>
const API_KEY  = <?= json_encode($apiKey) ?>;
const API_BASE = 'api/analytics.php';
let calcs = [];
let lastCalc = null;
let evaluateDebounce = null;

function setStatus(msg, color) {
  const el = document.getElementById('api-status');
  if (el) { el.textContent = msg; el.style.color = color || 'var(--muted)'; }
}

async function api(action, body) {
  const url = `${API_BASE}?action=${action}&api_key=${encodeURIComponent(API_KEY)}`;
  const opts = body
    ? { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) }
    : { method:'GET' };
  const r = await fetch(url, opts);
  const j = await r.json();
  if (!j.success) throw new Error(j.error || 'API error');
  return j;
}

function tabNext(e, nextId) {
  if (e.key === 'Enter' || e.key === 'Tab') {
    e.preventDefault();
    document.getElementById(nextId).focus();
    calcPos();
  }
}

function calcPos() {
  const capital = parseFloat(document.getElementById('c-capital').value) || 0;
  const riskPct = parseFloat(document.getElementById('c-risk-pct').value) || 0;
  const entry   = parseFloat(document.getElementById('c-entry').value);
  const stop    = parseFloat(document.getElementById('c-stop').value);
  const target  = parseFloat(document.getElementById('c-target').value);
  const ticker  = (document.getElementById('c-ticker').value || '').toUpperCase();
  const el      = document.getElementById('calc-result');
  const saveBtn = document.getElementById('save-btn');
  const rulesCard = document.getElementById('rules-card');

  // Trigger rules evaluation (debounced) — runs even with partial inputs
  scheduleRulesEval(ticker, entry, stop, target);

  if (!entry || !stop || entry <= stop) {
    el.style.display = 'none';
    saveBtn.style.display = 'none';
    lastCalc = null;
    return;
  }

  const riskUsd   = capital * riskPct / 100;
  const diff      = entry - stop;
  const lot       = Math.floor(riskUsd / diff);
  if (lot <= 0) { el.style.display = 'none'; saveBtn.style.display = 'none'; return; }

  const rr2       = entry + diff * 2;
  const rr3       = entry + diff * 3;
  const profit2   = lot * (rr2 - entry);
  const profit3   = lot * (rr3 - entry);
  const actualRisk= lot * diff;
  const rrActual  = target ? ((target - entry) / diff).toFixed(1) : null;
  const posSize   = lot * entry;
  const pctOfCap  = (posSize / capital * 100).toFixed(1);
  const sizeWarn  = posSize > capital * 0.5
    ? `<br><span class="warn">⚠ Позиция ${pctOfCap}% от капитала — осторожно</span>` : '';
  const rrWarn    = rrActual && rrActual < 2
    ? `<br><span class="err">✗ R:R = ${rrActual} — ниже минимума 2R</span>` : '';

  el.className = 'calc-result ready';
  el.style.display = 'block';
  el.innerHTML = `
    ${ticker ? `<strong>${ticker}</strong> &nbsp;` : ''}<strong>@$${entry}</strong> &nbsp; Стоп $${stop}<br>
    Лот: <span class="big">${lot} акций</span> &nbsp; ($${posSize.toLocaleString('ru')})<br>
    Риск: <strong>$${actualRisk.toFixed(0)}</strong> (${riskPct}% капитала)<br>
    Цель 2R → $${rr2.toFixed(2)}: <strong>+$${profit2.toFixed(0)}</strong> <span style="color:var(--muted);font-size:12px;">(+${(profit2/posSize*100).toFixed(1)}% от позиции)</span><br>
    Цель 3R → $${rr3.toFixed(2)}: <strong>+$${profit3.toFixed(0)}</strong> <span style="color:var(--muted);font-size:12px;">(+${(profit3/posSize*100).toFixed(1)}% от позиции)</span>
    ${rrActual ? `<br>Твоя цель: <strong>${rrActual}R</strong>${rrWarn}` : ''}${sizeWarn}
  `;

  saveBtn.style.display = '';
  lastCalc = {
    note_date  : new Date().toISOString().split('T')[0],
    symbol     : ticker || '—',
    entry      : entry,
    stop_price : stop,
    stop_diff  : Number(diff.toFixed(4)),
    target     : target || null,
    lot        : lot,
    risk_usd   : Number(actualRisk.toFixed(2)),
    rr2_target : Number(rr2.toFixed(4)),
    rr3_target : Number(rr3.toFixed(4)),
    profit_2r  : Number(profit2.toFixed(2)),
    profit_3r  : Number(profit3.toFixed(2)),
    capital    : capital,
    risk_pct   : riskPct,
  };
}

// ---- 6 Rules check (server-side, debounced) ----
function scheduleRulesEval(ticker, entry, stop, target) {
  clearTimeout(evaluateDebounce);
  // Need at least a ticker OR (entry+stop) to be useful
  if (!ticker && (!entry || !stop)) {
    document.getElementById('rules-card').style.display = 'none';
    return;
  }
  evaluateDebounce = setTimeout(() => evaluateRules(ticker, entry, stop, target), 350);
}

async function evaluateRules(ticker, entry, stop, target) {
  try {
    const payload = {
      symbol : ticker || '',
      entry  : entry  || '',
      stop   : stop   || '',
      target : target || '',
    };
    const r = await api('evaluate_setup', payload);
    renderRules(r);
  } catch (e) {
    document.getElementById('rules-card').style.display = 'block';
    document.getElementById('rules-verdict').className = 'rules-verdict no';
    document.getElementById('rules-verdict').innerHTML = `Ошибка проверки правил: ${e.message}`;
    document.getElementById('rules-list').innerHTML = '';
  }
}

function renderRules(r) {
  const card = document.getElementById('rules-card');
  card.style.display = 'block';

  // Verdict
  const v = r.verdict || {};
  const cnt = r.counts || {};
  const verdictEl = document.getElementById('rules-verdict');
  const cls = (v.code || '').toLowerCase();
  verdictEl.className = 'rules-verdict ' + cls;
  verdictEl.innerHTML = `
    <span>${v.code === 'OK' ? '✓' : v.code === 'NO' ? '✗' : v.code === 'CAUTION' ? '⚠' : '?'} ${v.label || ''}</span>
    <span class="rules-verdict-counts">${cnt.ok || 0} OK · ${cnt.warn || 0} warn · ${cnt.fail || 0} fail · ${cnt.manual || 0} ручных</span>
  `;

  // Rules list
  const icon = s => s === 'ok' ? '✓' : s === 'fail' ? '✗' : s === 'warn' ? '⚠' : '?';
  const ul = document.getElementById('rules-list');
  ul.innerHTML = (r.rules || []).map(rule => `
    <li>
      <span class="rule-icon ${rule.status}">${rule.n}. ${icon(rule.status)}</span>
      <div>
        <div class="rule-title">${rule.title}</div>
        <div class="rule-reason">${rule.reason}</div>
      </div>
    </li>
  `).join('');
}

async function saveCalc() {
  if (!lastCalc) return;
  const btn = document.getElementById('save-btn');
  btn.textContent = 'Сохраняю…'; btn.disabled = true;
  setStatus('Сохраняю…', 'var(--amber)');
  try {
    await api('save_calc', lastCalc);
    setStatus('Сохранено ✓', 'var(--green)');
    await loadCalcs();
    resetCalc();
  } catch (e) {
    setStatus('Ошибка: ' + e.message, 'var(--red)');
    alert('Не удалось сохранить: ' + e.message);
  } finally {
    btn.textContent = 'Сохранить'; btn.disabled = false;
  }
}

async function deleteCalc(id) {
  if (!confirm('Удалить расчёт?')) return;
  try {
    await api('delete_calc', { id });
    setStatus('Удалено ✓', 'var(--green)');
    await loadCalcs();
  } catch (e) {
    setStatus('Ошибка: ' + e.message, 'var(--red)');
  }
}

async function loadCalcs() {
  setStatus('Загружаю…', 'var(--amber)');
  try {
    const data = await api('list_calcs');
    calcs = data.calcs || [];
    setStatus(calcs.length ? `${calcs.length} расчётов` : 'Нет записей', 'var(--muted)');
    renderCalcs();
  } catch (e) {
    setStatus('Ошибка загрузки: ' + e.message, 'var(--red)');
  }
}

function renderCalcs() {
  const el = document.getElementById('calcLog');
  if (calcs.length === 0) {
    el.innerHTML = '<div style="text-align:center;padding:1.5rem;font-size:13px;color:var(--muted)">Сохранённых расчётов нет</div>';
    return;
  }
  el.innerHTML = calcs.map(c => `
    <div class="calc-entry">
      <div class="calc-entry-header">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <span class="calc-ticker">${c.symbol || '—'}</span>
          <span style="font-size:12px;color:var(--muted);">${c.note_date || ''}</span>
          <span style="font-size:12px;color:var(--muted);">@$${Number(c.entry).toFixed(2)} · ${c.lot} акций</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <span style="font-family:'DM Mono',monospace;font-size:13px;color:var(--green);">+$${Math.round(c.profit_2r).toLocaleString('en-US')}</span>
          <button class="btn sm danger" onclick="deleteCalc(${c.id})">✕</button>
        </div>
      </div>
      <div class="calc-nums">
        Стоп: <span class="r">$${Number(c.stop_price).toFixed(2)}</span> &nbsp;·&nbsp;
        Риск: $${Math.round(c.risk_usd)} &nbsp;·&nbsp;
        ТП 2R: <span class="g">$${Number(c.rr2_target).toFixed(2)}</span> (+$${Math.round(c.profit_2r)}) &nbsp;·&nbsp;
        ТП 3R: <span class="g">$${Number(c.rr3_target).toFixed(2)}</span> (+$${Math.round(c.profit_3r)})
      </div>
    </div>
  `).join('');
}

function resetCalc() {
  ['c-ticker','c-entry','c-stop','c-target'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('calc-result').style.display = 'none';
  document.getElementById('save-btn').style.display = 'none';
  document.getElementById('rules-card').style.display = 'none';
  lastCalc = null;
  document.getElementById('c-entry').focus();
}

loadCalcs();
</script>
</body>
</html>
