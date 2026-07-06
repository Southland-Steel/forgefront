<?php
// Sign-in diagnostics: recent sign-in log entries for one user, with
// IP / location / client app / failure reason and links to deeper info.
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';

$upn      = trim($_GET['user'] ?? '');
$signIns  = [];
$syncError = null;
$userRow  = null;

// Handle add/remove of a known IP, then redirect back (POST/redirect/GET).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionIp = trim($_POST['ip'] ?? '');
    if ($actionIp !== '') {
        if (($_POST['action'] ?? '') === 'remove') {
            removeKnownIp($actionIp);
        } else {
            addKnownIp($actionIp, trim($_POST['label'] ?? ''), $_POST['type'] ?? 'other', 'signins.php');
        }
    }
    header('Location: signins.php' . ($upn !== '' ? '?user=' . urlencode($upn) : ''));
    exit;
}

$known = knownIps();          // ip => label (from the known-IP DB)
$dbUp  = db() !== null;       // whether the known-IP store is writable

if ($upn !== '') {
    $cacheKey = 'signins_' . md5(strtolower($upn));
    try {
        if (isset($_GET['refresh'])) cacheForget($cacheKey);
        $signIns = cacheRemember($cacheKey, 120,
            fn() => fetchUserSignIns(getEntraAccessToken(), $upn, 50));
        $userRow = findCachedUser($upn);
    } catch (Throwable $e) {
        $syncError = $e->getMessage();
    }
}

// Helpers -------------------------------------------------------------
function loc(array $s): string {
    $l = $s['location'] ?? [];
    $parts = array_filter([$l['city'] ?? '', $l['state'] ?? '', $l['countryOrRegion'] ?? '']);
    return $parts ? implode(', ', $parts) : '—';
}
function device(array $s): string {
    $d = $s['deviceDetail'] ?? [];
    $parts = array_filter([$d['operatingSystem'] ?? '', $d['browser'] ?? '']);
    return $parts ? implode(' · ', $parts) : '—';
}
function isSuccess(array $s): bool {
    return (int) ($s['status']['errorCode'] ?? -1) === 0;
}
// Portal deep link to this user's sign-in logs (needs the object id)
$portalLink = $userRow['id'] ?? null
    ? 'https://entra.microsoft.com/#view/Microsoft_AAD_IAM/UserDetailsMenuBlade/~/SignIns/userId/' . rawurlencode($userRow['id'])
    : 'https://entra.microsoft.com/#view/Microsoft_AAD_IAM/SignInEventsV3Blade';

$last = $signIns[0] ?? null;   // most recent attempt

// Successful-sign-in summary — the compromise-vs-noise signals.
$succEvents = array_filter($signIns, fn($s) => (int) ($s['status']['errorCode'] ?? 0) === 0);
$succIps = [];
$succCountries = [];
$legacySucc = 0;
$succIpDetail = [];   // ip => [count, loc, legacy]
foreach ($succEvents as $s) {
    $ip = $s['ipAddress'] ?? '';
    $c = $s['location']['countryOrRegion'] ?? '';
    if ($c !== '') $succCountries[$c] = ($succCountries[$c] ?? 0) + 1;
    if (isLegacyAuth($s)) $legacySucc++;
    if ($ip === '') continue;
    $succIps[$ip] = 1;
    if (!isset($succIpDetail[$ip])) {
        $succIpDetail[$ip] = ['count' => 0, 'loc' => trim(($s['location']['city'] ?? '') . '/' . $c, '/'), 'legacy' => false];
    }
    $succIpDetail[$ip]['count']++;
    if (isLegacyAuth($s)) $succIpDetail[$ip]['legacy'] = true;
}
arsort($succCountries);
uasort($succIpDetail, fn($a, $b) => $b['count'] <=> $a['count']);
$homeCountry = $succCountries ? array_key_first($succCountries) : '';
$foreignSucc = 0;
foreach ($succCountries as $c => $n) if ($c !== $homeCountry) $foreignSucc += $n;

if (!$succEvents) {
    $verdictCls = 'muted'; $verdictTxt = 'No successful sign-ins in this window.';
} elseif ($foreignSucc > 0) {
    $verdictCls = 'fail'; $verdictTxt = 'Successful sign-ins from more than one country — review for possible compromise.';
} elseif ($legacySucc > 0) {
    $verdictCls = 'warn'; $verdictTxt = 'Successful legacy (MFA-bypassing) sign-ins present — confirm the source is a known device/app.';
} else {
    $verdictCls = 'ok'; $verdictTxt = 'Single country, no legacy successes — pattern looks normal. Many IPs alone (e.g. a phone on cellular) is not a compromise signal.';
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/_nav.php';
?>
<style>
        .search { margin: 12px 0 20px; }
        .search input { padding: 9px 12px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.95em; width: 360px; }
        .search button { padding: 9px 16px; border: none; background: #1e3d59; color: #fff; border-radius: 6px; font-weight: 600; cursor: pointer; margin-left: 8px; }
        /* "et-" prefix avoids colliding with Bootstrap's own .card classes */
        .et-card { border: 1px solid #e1e8ed; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px; }
        .et-card.fail { border-color: #f0b4a8; background: #fdf4f2; }
        .et-card.ok { border-color: #b7e4cf; background: #f2faf6; }
        .et-card h3 { margin: 0 0 12px; color: #1e3d59; }
        .kv { display: grid; grid-template-columns: 160px 1fr; gap: 6px 16px; }
        .kv dt { color: #657786; font-weight: 600; }
        .kv dd { margin: 0; word-break: break-word; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.85em; font-weight: bold; }
        .pill.ok { background: #e8f8f5; color: #2e7d32; }
        .pill.fail { background: #fce4d6; color: #c0392b; }
        .pill.warn { background: #fff4e0; color: #8a5a00; }
        .pill.muted { background: #eef3f8; color: #657786; }
        .legacy-badge { display: inline-block; background: #fce4d6; color: #c0392b; border-radius: 10px; padding: 1px 7px; font-size: 0.72em; font-weight: 700; margin-left: 4px; }
        .sum-card { border: 1px solid #e1e8ed; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; }
        .sum-card.ok { border-color: #b7e4cf; background: #f2faf6; }
        .sum-card.warn { border-color: #f0d9a8; background: #fff9ef; }
        .sum-card.fail { border-color: #f0b4a8; background: #fdf4f2; }
        .sum-metrics { display: flex; gap: 24px; flex-wrap: wrap; margin: 10px 0; }
        .sum-metrics .m { font-size: 0.9em; color: #657786; }
        .sum-metrics .m b { display: block; font-size: 1.4em; color: #1e3d59; font-weight: 700; }
        .sum-verdict { font-weight: 600; }
        a.deep { color: #1e6fce; text-decoration: none; font-weight: 600; }
        a.deep:hover { text-decoration: underline; }
        .mono { font-family: ui-monospace, monospace; font-size: 0.85em; }
        .hint { color: #6c757d; font-size: 0.9em; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header mb-2">
        <h4 class="page-title">Sign-in Diagnostics</h4>
        <p class="hint mb-0">Recent sign-in log entries from Entra (auditLogs/signIns). Includes failed and non-interactive attempts.</p>
    </div>

    <form class="search" method="get">
        <input type="text" name="user" value="<?php echo htmlspecialchars($upn); ?>" placeholder="user@domain.com" autocomplete="off">
        <button type="submit">Diagnose</button>
        <?php if ($upn !== ''): ?>
            <a class="deep" style="margin-left:12px" href="?user=<?php echo urlencode($upn); ?>&refresh=1">↻ Refresh</a>
        <?php endif; ?>
    </form>

    <?php if ($syncError): ?>
        <div class="alert alert-danger">Sign-in query failed: <?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>

    <?php if ($upn !== '' && !$syncError): ?>
        <p class="hint">
            <?php echo htmlspecialchars($userRow['displayName'] ?? $upn); ?>
            <?php if ($userRow && !($userRow['accountEnabled'] ?? true)): ?>
                — <span class="pill fail">Account Disabled</span>
            <?php endif; ?>
            · <a class="deep" href="<?php echo htmlspecialchars($portalLink); ?>" target="_blank" rel="noopener">Open in Entra portal ↗</a>
        </p>

        <?php if ($last): ?>
            <div class="sum-card <?php echo $verdictCls; ?>">
                <h3 style="margin-top:0;color:#1e3d59">Successful Sign-in Summary
                    <span class="pill <?php echo $verdictCls; ?>"><?php echo $verdictCls === 'ok' ? 'Looks normal' : ($verdictCls === 'fail' ? 'Review' : ($verdictCls === 'warn' ? 'Check' : '—')); ?></span>
                </h3>
                <div class="sum-metrics">
                    <div class="m"><b><?php echo count($succEvents); ?></b> successful</div>
                    <div class="m"><b><?php echo count($succIps); ?></b> distinct IPs</div>
                    <div class="m"><b><?php echo count($succCountries); ?></b> countr<?php echo count($succCountries) === 1 ? 'y' : 'ies'; ?></div>
                    <div class="m"><b><?php echo $foreignSucc; ?></b> foreign successes</div>
                    <div class="m"><b><?php echo $legacySucc; ?></b> legacy successes</div>
                </div>
                <?php if ($succCountries): ?>
                    <div class="m" style="color:#657786;font-size:0.85em;margin-bottom:8px">Countries:
                        <?php echo htmlspecialchars(implode(', ', array_map(fn($c, $n) => "$c ($n)", array_keys($succCountries), $succCountries))); ?>
                    </div>
                <?php endif; ?>
                <div class="sum-verdict"><?php echo htmlspecialchars($verdictTxt); ?></div>
            </div>

            <?php if ($succIpDetail): ?>
                <div class="et-card">
                    <h3 style="margin-top:0;color:#1e3d59">Successful Source IPs</h3>
                    <?php if (!$dbUp): ?>
                        <p class="hint">Known-IP store unavailable — showing config sites only. (Set DB_SQLITE_PATH in .env to save known IPs.)</p>
                    <?php endif; ?>
                    <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>IP</th><th>Logins</th><th>Location</th><th>Legacy</th><th>Known?</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($succIpDetail as $ip => $d):
                                $isKnown = isset($known[$ip]);
                            ?>
                                <tr>
                                    <td class="mono"><?php echo htmlspecialchars($ip); ?></td>
                                    <td><?php echo $d['count']; ?></td>
                                    <td><?php echo htmlspecialchars($d['loc'] ?: '—'); ?></td>
                                    <td><?php echo $d['legacy'] ? '<span class="legacy-badge">LEGACY</span>' : '—'; ?></td>
                                    <td>
                                        <?php if ($isKnown): ?>
                                            <span class="pill ok"><?php echo htmlspecialchars($known[$ip]); ?></span>
                                        <?php else: ?>
                                            <span class="pill warn">unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($dbUp && !$isKnown): ?>
                                            <form method="post" action="signins.php?user=<?php echo urlencode($upn); ?>" style="display:flex;gap:6px">
                                                <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                                                <input type="text" name="label" placeholder="label (e.g. Employee A / Customer A)" style="padding:4px 8px;border:1px solid #ccd6dd;border-radius:5px;font-size:0.85em">
                                                <select name="type" style="padding:4px 6px;border:1px solid #ccd6dd;border-radius:5px;font-size:0.85em">
                                                    <?php foreach (IP_TYPES as $t): ?><option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option><?php endforeach; ?>
                                                </select>
                                                <button class="deep" style="border:none;background:none;cursor:pointer" type="submit">+ mark known</button>
                                            </form>
                                        <?php elseif ($dbUp && $isKnown): ?>
                                            <form method="post" action="signins.php?user=<?php echo urlencode($upn); ?>">
                                                <input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
                                                <input type="hidden" name="action" value="remove">
                                                <button class="deep" style="border:none;background:none;cursor:pointer;color:#c0392b" type="submit">remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$last): ?>
            <div class="et-card"><em>No sign-in log entries found for this user in the retained window (typically 30 days).</em></div>
        <?php else:
            $ok = isSuccess($last);
            $code = (int) ($last['status']['errorCode'] ?? 0);
            $reason = $last['status']['failureReason'] ?? '';
        ?>
            <div class="et-card <?php echo $ok ? 'ok' : 'fail'; ?>">
                <h3>Most Recent Attempt
                    <span class="pill <?php echo $ok ? 'ok' : 'fail'; ?>"><?php echo $ok ? 'Success' : 'Failed'; ?></span>
                </h3>
                <dl class="kv">
                    <dt>When</dt><dd><?php echo htmlspecialchars($last['createdDateTime'] ?? '—'); ?></dd>
                    <dt>Type</dt><dd><?php echo ($last['isInteractive'] ?? false) ? 'Interactive (login prompt)' : 'Non-interactive (token refresh)'; ?></dd>
                    <dt>Result</dt>
                    <dd>
                        <?php if ($ok): ?>
                            Signed in successfully
                        <?php else: ?>
                            <strong>AADSTS<?php echo $code; ?></strong> — <?php echo htmlspecialchars($reason); ?>
                            &nbsp;<a class="deep" href="https://login.microsoftonline.com/error?code=<?php echo $code; ?>" target="_blank" rel="noopener">explain this error ↗</a>
                        <?php endif; ?>
                    </dd>
                    <dt>IP Address</dt><dd class="mono"><?php echo htmlspecialchars($last['ipAddress'] ?? '—'); ?></dd>
                    <dt>Location</dt><dd><?php echo htmlspecialchars(loc($last)); ?></dd>
                    <dt>Client App</dt><dd><?php echo htmlspecialchars($last['clientAppUsed'] ?? '—'); ?><?php if (isLegacyAuth($last)): ?> <span class="legacy-badge" title="Legacy protocol — bypasses MFA">LEGACY</span><?php endif; ?></dd>
                    <dt>Application</dt><dd><?php echo htmlspecialchars($last['appDisplayName'] ?? '—'); ?></dd>
                    <dt>Device</dt><dd><?php echo htmlspecialchars(device($last)); ?></dd>
                    <dt>Risk</dt><dd><?php echo htmlspecialchars(($last['riskLevelDuringSignIn'] ?? 'none') . ' / ' . ($last['riskState'] ?? 'none')); ?></dd>
                    <dt>Request ID</dt><dd class="mono"><?php echo htmlspecialchars($last['id'] ?? '—'); ?></dd>
                    <dt>Correlation ID</dt><dd class="mono"><?php echo htmlspecialchars($last['correlationId'] ?? '—'); ?></dd>
                </dl>
            </div>

            <h3 style="color:#1e3d59">Recent Sign-ins (<?php echo count($signIns); ?>)</h3>
            <div class="card">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>When</th><th>Result</th><th>Type</th><th>IP</th>
                        <th>Location</th><th>Client App</th><th>Application</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($signIns as $s):
                        $sok = isSuccess($s);
                        $scode = (int) ($s['status']['errorCode'] ?? 0);
                    ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars($s['createdDateTime'] ?? '—'); ?></td>
                            <td>
                                <?php if ($sok): ?>
                                    <span class="pill ok">OK</span>
                                <?php else: ?>
                                    <span class="pill fail">AADSTS<?php echo $scode; ?></span>
                                    <a class="deep" href="https://login.microsoftonline.com/error?code=<?php echo $scode; ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($s['status']['failureReason'] ?? ''); ?>">?</a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ($s['isInteractive'] ?? false) ? 'Interactive' : 'Non-int.'; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($s['ipAddress'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(loc($s)); ?></td>
                            <td><?php echo htmlspecialchars($s['clientAppUsed'] ?? '—'); ?><?php if (isLegacyAuth($s)): ?> <span class="legacy-badge">LEGACY</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($s['appDisplayName'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
