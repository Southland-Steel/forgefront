const DEFAULT_ENDPOINT = 'http://192.168.81.123/it_manager/ajax/scan_toggle_agent.php';
const DEFAULT_TOKEN    = '3b6713e0cf5f8b2c371b4d5bcde052bf636ba54b7cd7db83e01302f9e752f8ec';
const MAX_LOG          = 30;

chrome.runtime.onMessage.addListener((msg, sender) => {
    if (msg.type !== 'SCAN') return;

    chrome.storage.sync.get({ endpoint: DEFAULT_ENDPOINT, token: DEFAULT_TOKEN }, (cfg) => {
        fetch(cfg.endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ scan_data: msg.tag, token: cfg.token }),
        })
        .then(r => r.json())
        .then(d => {
            const result = {
                type:       'SCAN_RESULT',
                success:    d.success,
                tag:        msg.tag,
                action:     d.action     ?? null,
                asset_name: d.asset_name ?? null,
                old_status: d.old_status ?? null,
                new_status: d.new_status ?? null,
                error:      d.error      ?? null,
            };
            replyToTab(sender.tab, result);
            appendLog(result);
            flashBadge(d.success ? 'ok' : 'err');
        })
        .catch(() => {
            const result = { type: 'SCAN_RESULT', success: false, tag: msg.tag, error: 'Could not reach server' };
            replyToTab(sender.tab, result);
            appendLog(result);
            flashBadge('err');
        });
    });
});

function replyToTab(tab, msg) {
    if (tab && tab.id) chrome.tabs.sendMessage(tab.id, msg);
}

function flashBadge(state) {
    chrome.browserAction.setBadgeText({ text: state === 'ok' ? '✓' : '!' });
    chrome.browserAction.setBadgeBackgroundColor({ color: state === 'ok' ? '#16a34a' : '#dc2626' });
    setTimeout(() => chrome.browserAction.setBadgeText({ text: '' }), 3000);
}

function appendLog(result) {
    chrome.storage.local.get({ scanLog: [] }, (data) => {
        const entry = {
            time:       new Date().toISOString(),
            tag:        result.tag,
            success:    result.success,
            action:     result.action,
            asset_name: result.asset_name,
            old_status: result.old_status,
            new_status: result.new_status,
            error:      result.error,
        };
        chrome.storage.local.set({ scanLog: [entry, ...data.scanLog].slice(0, MAX_LOG) });
    });
}
