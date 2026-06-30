const DEFAULT_ENDPOINT = 'http://192.168.81.123/it_manager/ajax/scan_toggle_agent.php';
const DEFAULT_TOKEN    = '3b6713e0cf5f8b2c371b4d5bcde052bf636ba54b7cd7db83e01302f9e752f8ec';

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById(tab.dataset.tab + 'Panel').classList.add('active');
    });
});

// ── Connection badge ──────────────────────────────────────────────────────────
function checkConnection(endpoint, token) {
    const badge = document.getElementById('connBadge');
    badge.textContent = 'Checking…';
    badge.className   = 'conn-badge';
    fetch(endpoint, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ scan_data: '', token }),
    })
    .then(r => r.json())
    .then(() => {
        badge.textContent = 'Connected';
        badge.className   = 'conn-badge ok';
    })
    .catch(() => {
        badge.textContent = 'Unreachable';
        badge.className   = 'conn-badge err';
    });
}

// ── Settings ──────────────────────────────────────────────────────────────────
chrome.storage.sync.get({ endpoint: DEFAULT_ENDPOINT, token: DEFAULT_TOKEN }, (cfg) => {
    document.getElementById('cfgEndpoint').value = cfg.endpoint;
    document.getElementById('cfgToken').value    = cfg.token;
    checkConnection(cfg.endpoint, cfg.token);
});

document.getElementById('saveBtn').addEventListener('click', () => {
    const cfg = {
        endpoint: document.getElementById('cfgEndpoint').value.trim() || DEFAULT_ENDPOINT,
        token:    document.getElementById('cfgToken').value.trim()    || DEFAULT_TOKEN,
    };
    chrome.storage.sync.set(cfg, () => {
        const msg = document.getElementById('savedMsg');
        msg.style.display = 'block';
        setTimeout(() => msg.style.display = 'none', 2000);
        checkConnection(cfg.endpoint, cfg.token);
    });
});

// ── Scan log ──────────────────────────────────────────────────────────────────
function renderLog() {
    chrome.storage.local.get({ scanLog: [] }, (data) => {
        const container = document.getElementById('logContainer');
        if (!data.scanLog.length) {
            container.innerHTML = '<div class="log-empty">No scans yet.</div>';
            return;
        }
        container.innerHTML = data.scanLog.map(e => {
            const time = new Date(e.time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            if (e.success) {
                return `<div class="log-entry">
                    <div class="log-tag ok">${e.tag}</div>
                    <div class="log-sub">${e.action || ''} · ${e.asset_name || ''}</div>
                    <div class="log-sub">${e.old_status || ''} → ${e.new_status || ''} <span class="log-time">${time}</span></div>
                </div>`;
            } else {
                return `<div class="log-entry">
                    <div class="log-tag err">${e.tag || '?'}</div>
                    <div class="log-sub" style="color:#dc2626">${e.error || 'Error'}</div>
                    <div class="log-time">${time}</div>
                </div>`;
            }
        }).join('');
    });
}

document.getElementById('clearLogBtn').addEventListener('click', () => {
    chrome.storage.local.set({ scanLog: [] }, renderLog);
});

renderLog();
