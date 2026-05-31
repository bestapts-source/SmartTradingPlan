<?php
define('TRADING_BOOT', true);
require __DIR__ . '/api/config.php';
$apiKey = defined('API_KEY') ? API_KEY : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Дневник · Vadim Shteiman</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
  body[data-page="journal"] main{max-width:920px;}

  .jform{display:flex; flex-direction:column; gap:14px; margin-top:14px;}
  .jrow{display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px;}
  .jrow1{display:flex; flex-direction:column;}
  .jform label{font-size:11px; color:var(--muted); font-family:'DM Mono',monospace;
               text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px;}
  .jform input, .jform select, .jform textarea{
    background:var(--bg3); color:var(--text);
    border:.5px solid var(--border); border-radius:8px;
    padding:8px 12px; font-family:'DM Mono',monospace; font-size:13px; width:100%;
  }
  .jform textarea{min-height:60px; resize:vertical; font-family:'DM Sans',sans-serif;}
  .jform input:focus, .jform select:focus, .jform textarea:focus{
    outline:none; border-color:var(--gold);
  }

  .btn{
    background:var(--gold); color:#0e0f0d; border:none; border-radius:8px;
    padding:9px 20px; font-family:'DM Mono',monospace; font-size:12px; font-weight:500;
    text-transform:uppercase; letter-spacing:.08em; cursor:pointer;
  }
  .btn:hover{filter:brightness(1.1);}
  .btn.secondary{background:var(--bg3); color:var(--text); border:.5px solid var(--border);}
  .btn.danger{background:transparent; color:var(--red); border:.5px solid var(--red);}
  .btn.sm{padding:5px 10px; font-size:10px;}

  .api-status{font-family:'DM Mono',monospace; font-size:11px; color:var(--muted); margin-left:auto;}

  #tradeLog{margin-top:24px; display:flex; flex-direction:column; gap:8px;}
  .trade-entry{background:var(--bg2); border:.5px solid var(--border); border-radius:10px; padding:14px 18px;}
  .te-header{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;}
  .te-ticker{font-family:'DM Mono',monospace; font-weight:500; color:var(--text); font-size:15px;}
  .te-date{font-family:'DM Mono',monospace; font-size:11px; color:var(--gold);}
  .te-tag{
    font-family:'DM Mono',monospace; font-size:10px; padding:3px 8px; border-radius:4px;
    text-transform:uppercase;
  }
  .te-tag.good{background:var(--green-dim); color:var(--green);}
  .te-tag.regret{background:var(--red-dim); color:var(--red);}
  .te-meta{color:var(--muted); font-size:12px; margin-top:4px; font-family:'DM Mono',monospace;}
  .te-body{color:var(--muted); font-size:13px; margin-top:8px; line-height:1.5;}
  .te-body.lesson{color:var(--amber); margin-top:4px;}
  .summary-bar{
    display:flex; flex-wrap:wrap; gap:18px; background:var(--bg2);
    border:.5px solid var(--border); border-radius:10px;
    padding:12px 18px; margin-top:16px;
  }
  .sb-item{display:flex; flex-direction:column;}
  .sb-val{font-family:'DM Mono',monospace; font-size:18px; font-weight:500;}
  .sb-label{font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em;}
</style>
</head>
<body data-page="journal">

<header id="site-header"></header>
<nav class="nav" id="site-nav"></nav>

<main>
  <div class="sec-label">
    Новая запись
    <span class="api-status" id="api-status">Загружаю…</span>
  </div>

  <div class="card green">
    <span class="tag green">Запиши пока эмоции свежие</span>
    <form class="jform" id="journal-form" onsubmit="event.preventDefault(); saveEntry();">
      <input type="hidden" id="j-edit-id" value="">
      <div class="jrow">
        <div><label>Дата</label><input type="date" id="j-date" required></div>
        <div><label>Тикер</label><input type="text" id="j-ticker" placeholder="RKLB" style="text-transform:uppercase" required></div>
      </div>
      <div class="jrow">
        <div>
          <label>Эмоция до</label>
          <select id="j-emo-before">
            <option value="">—</option>
            <option value="Calm">Calm — спокоен</option>
            <option value="Confident">Confident — уверен</option>
            <option value="FOMO">FOMO — боюсь упустить</option>
            <option value="Anxious">Anxious — тревожен</option>
            <option value="Greedy">Greedy — жадничаю</option>
            <option value="Neutral">Neutral</option>
          </select>
        </div>
        <div>
          <label>Эмоция после</label>
          <select id="j-emo-after">
            <option value="">—</option>
            <option value="Satisfied">Satisfied — доволен</option>
            <option value="Regret">Regret — жалею</option>
            <option value="Neutral">Neutral</option>
            <option value="Proud">Proud — горд</option>
            <option value="Frustrated">Frustrated — расстроен</option>
          </select>
        </div>
      </div>
      <div class="jrow">
        <div>
          <label>Сетап</label>
          <input type="text" id="j-setup" placeholder="пробой, откат к 20 EMA…">
        </div>
        <div style="display:flex; gap:18px; align-items:center; padding-top:24px;">
          <label style="display:flex; gap:8px; align-items:center; font-family:'DM Mono',monospace; font-size:11px; color:var(--green);">
            <input type="checkbox" id="j-good"> Хорошая сделка
          </label>
          <label style="display:flex; gap:8px; align-items:center; font-family:'DM Mono',monospace; font-size:11px; color:var(--red);">
            <input type="checkbox" id="j-regret"> Regret
          </label>
        </div>
      </div>
      <div class="jrow1">
        <label>Что произошло?</label>
        <textarea id="j-free" rows="3" placeholder="Увидел пробой с объёмом, вошёл на откате к 20 EMA…"></textarea>
      </div>
      <div class="jrow1">
        <label>Урок · что сделал бы по-другому?</label>
        <textarea id="j-lesson" rows="2" placeholder="Вышел слишком рано…"></textarea>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button class="btn" type="submit" id="save-btn">Сохранить</button>
        <button class="btn secondary" type="button" id="cancel-edit-btn" onclick="cancelEdit()" style="display:none">Отмена</button>
        <button class="btn secondary" type="button" onclick="exportLog()">Экспорт CSV</button>
      </div>
    </form>
  </div>

  <div id="tradeLog"></div>
  <div id="journal-summary" class="summary-bar" style="display:none;"></div>
</main>

<script src="nav.js?v=<?= filemtime(__DIR__ . '/nav.js') ?>"></script>
<script>
const API_KEY  = <?= json_encode($apiKey) ?>;
const API_BASE = 'api/analytics.php';

let notes = [];

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

async function loadEntries() {
  setStatus('Загружаю…', 'var(--amber)');
  try {
    const r = await api('notes');
    notes = r.notes || [];
    setStatus(`Синхронизировано (${notes.length}) ✓`, 'var(--green)');
    renderLog();
  } catch (e) {
    setStatus('Ошибка: ' + e.message, 'var(--red)');
  }
}

document.getElementById('j-date').value = new Date().toISOString().split('T')[0];

function clearForm() {
  document.getElementById('j-edit-id').value = '';
  document.getElementById('j-ticker').value = '';
  document.getElementById('j-emo-before').value = '';
  document.getElementById('j-emo-after').value = '';
  document.getElementById('j-setup').value = '';
  document.getElementById('j-good').checked = false;
  document.getElementById('j-regret').checked = false;
  document.getElementById('j-free').value = '';
  document.getElementById('j-lesson').value = '';
  document.getElementById('j-date').value = new Date().toISOString().split('T')[0];
  document.getElementById('save-btn').textContent = 'Сохранить';
  document.getElementById('cancel-edit-btn').style.display = 'none';
}

function cancelEdit() { clearForm(); }

async function saveEntry() {
  const editId = document.getElementById('j-edit-id').value;
  const payload = {
    symbol         : document.getElementById('j-ticker').value.trim().toUpperCase(),
    note_date      : document.getElementById('j-date').value,
    emotion_before : document.getElementById('j-emo-before').value || null,
    emotion_after  : document.getElementById('j-emo-after').value || null,
    setup          : document.getElementById('j-setup').value || null,
    good_trade     : document.getElementById('j-good').checked ? 1 : 0,
    regret_flag    : document.getElementById('j-regret').checked ? 1 : 0,
    lesson         : document.getElementById('j-lesson').value || null,
    free_text      : document.getElementById('j-free').value || null,
  };
  if (editId) payload.id = editId;
  if (!payload.symbol || !payload.note_date) { alert('Укажи дату и тикер'); return; }

  const btn = document.getElementById('save-btn');
  btn.disabled = true; btn.textContent = 'Сохраняю…';
  setStatus('Сохраняю…', 'var(--amber)');
  try {
    await api('save_note', payload);
    setStatus('Сохранено ✓', 'var(--green)');
    clearForm();
    await loadEntries();
  } catch (e) {
    setStatus('Ошибка: ' + e.message, 'var(--red)');
    alert('Не удалось сохранить: ' + e.message);
  } finally {
    btn.disabled = false;
  }
}

function editEntry(id) {
  const n = notes.find(x => String(x.id) === String(id));
  if (!n) return;
  document.getElementById('j-edit-id').value   = n.id;
  document.getElementById('j-date').value      = n.note_date || '';
  document.getElementById('j-ticker').value    = n.symbol;
  document.getElementById('j-emo-before').value= n.emotion_before || '';
  document.getElementById('j-emo-after').value = n.emotion_after  || '';
  document.getElementById('j-setup').value     = n.setup    || '';
  document.getElementById('j-good').checked    = n.good_trade == 1;
  document.getElementById('j-regret').checked  = n.regret_flag == 1;
  document.getElementById('j-free').value      = n.free_text || '';
  document.getElementById('j-lesson').value    = n.lesson || '';
  document.getElementById('save-btn').textContent = 'Сохранить изменения';
  document.getElementById('cancel-edit-btn').style.display = '';
  document.getElementById('journal-form').scrollIntoView({ behavior:'smooth', block:'start' });
}

async function deleteEntry(id) {
  if (!confirm('Удалить запись?')) return;
  setStatus('Удаляю…', 'var(--amber)');
  try {
    await api('delete_note', { id });
    setStatus('Удалено ✓', 'var(--green)');
    await loadEntries();
  } catch (e) {
    setStatus('Ошибка: ' + e.message, 'var(--red)');
  }
}

function renderLog() {
  const el  = document.getElementById('tradeLog');
  const sum = document.getElementById('journal-summary');
  if (notes.length === 0) {
    el.innerHTML = '<div style="text-align:center; padding:2rem; font-size:13px; color:var(--muted)">Пока нет записей. Добавь первую сверху.</div>';
    sum.style.display = 'none';
    return;
  }
  el.innerHTML = notes.map(n => {
    const tags = [];
    if (n.good_trade == 1)  tags.push('<span class="te-tag good">✓ хорошая</span>');
    if (n.regret_flag == 1) tags.push('<span class="te-tag regret">✗ regret</span>');
    const meta = [];
    if (n.emotion_before) meta.push(`до: ${n.emotion_before}`);
    if (n.emotion_after)  meta.push(`после: ${n.emotion_after}`);
    if (n.setup) meta.push(`сетап: ${n.setup}`);
    const importTag = n.period_start
      ? `<span class="te-tag" style="background:rgba(195,165,76,0.12);color:var(--gold);">привязано к ${n.period_start}…${n.period_end}</span>`
      : '<span class="te-tag" style="background:rgba(255,255,255,0.06);color:var(--muted);">без привязки</span>';
    return `
      <div class="trade-entry">
        <div class="te-header">
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <span class="te-ticker">${n.symbol}</span>
            ${tags.join('')}
            <span class="te-date">${n.note_date || '—'}</span>
            ${importTag}
          </div>
          <div style="display:flex; gap:6px;">
            <button class="btn sm secondary" onclick="editEntry(${n.id})">Изменить</button>
            <button class="btn sm danger" onclick="deleteEntry(${n.id})">✕</button>
          </div>
        </div>
        ${meta.length ? `<div class="te-meta">${meta.join(' · ')}</div>` : ''}
        ${n.free_text ? `<div class="te-body">${escapeHtml(n.free_text)}</div>` : ''}
        ${n.lesson    ? `<div class="te-body lesson">→ ${escapeHtml(n.lesson)}</div>` : ''}
      </div>`;
  }).join('');

  const total  = notes.length;
  const good   = notes.filter(n => n.good_trade == 1).length;
  const regret = notes.filter(n => n.regret_flag == 1).length;
  const linked = notes.filter(n => n.import_id).length;
  sum.style.display = 'flex';
  sum.innerHTML = `
    <div class="sb-item"><span class="sb-val">${total}</span><span class="sb-label">всего записей</span></div>
    <div class="sb-item"><span class="sb-val" style="color:var(--green)">${good}</span><span class="sb-label">хороших</span></div>
    <div class="sb-item"><span class="sb-val" style="color:var(--red)">${regret}</span><span class="sb-label">regret</span></div>
    <div class="sb-item"><span class="sb-val" style="color:var(--gold)">${linked}</span><span class="sb-label">с привязкой к импорту</span></div>
  `;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function exportLog() {
  if (notes.length === 0) { alert('Нет записей'); return; }
  const rows = [['Дата','Тикер','Эмоция до','Эмоция после','Setup','Good','Regret','Free text','Урок','Привязка']];
  notes.forEach(n => rows.push([
    n.note_date || '', n.symbol, n.emotion_before || '', n.emotion_after || '',
    n.setup || '', n.good_trade == 1 ? 'yes':'', n.regret_flag == 1 ? 'yes':'',
    n.free_text || '', n.lesson || '',
    n.period_start ? `${n.period_start}…${n.period_end}` : ''
  ]));
  const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g,'""') + '"').join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,﻿' + encodeURIComponent(csv);
  a.download = 'journal_vadim.csv';
  a.click();
}

loadEntries();
</script>
</body>
</html>
