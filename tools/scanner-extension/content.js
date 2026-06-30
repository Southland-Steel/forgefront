// Runs on every page. Detects USB barcode scanner input by timing:
// scanners emit characters < 50ms apart, humans can't match that speed.

const MAX_CHAR_GAP_MS = 50;
const DEBOUNCE_MS     = 1500;
const ASSET_PATTERN   = /^FF-\d+$/i;

let buffer      = [];
let lastKeyTime = 0;
let lastTag     = null;
let lastTagTime = 0;

document.addEventListener('keydown', (e) => {
    const now = Date.now();

    if (e.key === 'Enter') {
        const tag = buffer.join('').trim().toUpperCase();
        buffer      = [];
        lastKeyTime = 0;

        if (ASSET_PATTERN.test(tag)) {
            if (tag === lastTag && (now - lastTagTime) < DEBOUNCE_MS) return;
            lastTag     = tag;
            lastTagTime = now;
            chrome.runtime.sendMessage({ type: 'SCAN', tag });
        }
        return;
    }

    // Reset buffer if gap is too large — that's a human, not a scanner
    if (buffer.length > 0 && (now - lastKeyTime) > MAX_CHAR_GAP_MS) {
        buffer = [];
    }

    lastKeyTime = now;
    if (e.key.length === 1) buffer.push(e.key);

}, true); // capture phase so we see it before the page does

// Receive result from background and show toast
chrome.runtime.onMessage.addListener((msg) => {
    if (msg.type === 'SCAN_RESULT') showToast(msg);
});

function showToast(d) {
    const existing = document.getElementById('ff-scanner-toast');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.id = 'ff-scanner-toast';
    el.style.cssText = [
        'position:fixed',
        'bottom:24px',
        'right:24px',
        'z-index:2147483647',
        'padding:14px 20px',
        'border-radius:12px',
        'font-family:system-ui,-apple-system,sans-serif',
        'font-size:13px',
        'line-height:1.5',
        'pointer-events:none',
        'box-shadow:0 6px 24px rgba(0,0,0,.22)',
        'max-width:300px',
        'transition:opacity .3s ease',
        d.success ? 'background:#16a34a;color:#fff' : 'background:#dc2626;color:#fff',
    ].join(';');

    if (d.success) {
        el.innerHTML =
            `<div style="font-weight:700;font-size:15px;margin-bottom:2px">${d.action} ✓</div>` +
            `<div style="font-family:monospace;font-size:15px;font-weight:700">${d.tag}</div>` +
            (d.asset_name ? `<div style="opacity:.85;font-size:12px;margin-top:2px">${d.asset_name}</div>` : '') +
            `<div style="opacity:.85;font-size:12px">${d.old_status} → ${d.new_status}</div>`;
    } else {
        el.innerHTML =
            `<div style="font-weight:700;font-size:15px;margin-bottom:2px">Scan Failed</div>` +
            (d.tag ? `<div style="font-family:monospace;font-size:14px">${d.tag}</div>` : '') +
            `<div style="opacity:.85;font-size:12px;margin-top:2px">${d.error || 'Unknown error'}</div>`;
    }

    document.body.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 300);
    }, 4000);
}
