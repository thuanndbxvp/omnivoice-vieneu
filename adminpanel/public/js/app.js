// app.js — shared admin panel JS

// ── Copy to clipboard ─────────────────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    showToast('Copied: ' + text);
  }).catch(() => {
    const el = document.createElement('textarea');
    el.value = text;
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    showToast('Copied');
  });
}

// ── Toast notification ────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  requestAnimationFrame(() => t.classList.add('show'));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2500);
}

// ── Generate license key ──────────────────────────────────────
function generateLicenseKey(targetId) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  const part = () => Array.from({length: 4}, () => chars[Math.floor(Math.random() * chars.length)]).join('');
  const key = `${part()}-${part()}-${part()}-${part()}`;
  const el = document.getElementById(targetId);
  if (el) el.value = key;
}

// ── Confirm before destructive action ────────────────────────
function confirmAction(msg) {
  return confirm(msg || 'Are you sure?');
}

// ── Bulk copy keys ────────────────────────────────────────────
function copyAllKeys(textareaId) {
  const el = document.getElementById(textareaId);
  if (el) copyText(el.value);
}

// ── Toast style injection (inline) ───────────────────────────
(function () {
  const style = document.createElement('style');
  style.textContent = `
    .toast {
      position: fixed; bottom: 24px; right: 24px;
      background: #1e293b; color: #e2e8f0;
      border: 1px solid #334155;
      padding: 10px 18px; border-radius: 8px;
      font-size: 13px; z-index: 9999;
      transform: translateY(20px); opacity: 0;
      transition: transform .25s, opacity .25s;
      box-shadow: 0 8px 24px rgba(0,0,0,.4);
    }
    .toast.show { transform: translateY(0); opacity: 1; }
    .toast-error { border-color: #ef4444; color: #fca5a5; }
  `;
  document.head.appendChild(style);
})();
