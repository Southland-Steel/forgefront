<?php
/**
 * Entra ID (Azure AD) integration layer.
 * Loads credentials from .env, fetches all users with licenses,
 * and provides a helper to stamp app-role JSON onto a user's
 * extension attribute for downstream SSO consumption.
 */

// Gate every entratrak page behind ForgeFront's own login — this file is
// included first by every page in the module.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();

// ---------------------------------------------------------------
// Environment loading
// ---------------------------------------------------------------
function loadEnv(string $path): void {
    if (!is_readable($path)) {
        throw new RuntimeException(".env file not found at $path — copy .env.example to .env and fill it in.");
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/../.env');

define('TENANT_ID', getenv('ENTRA_TENANT_ID'));
define('CLIENT_ID', getenv('ENTRA_CLIENT_ID'));
define('CLIENT_SECRET', getenv('ENTRA_CLIENT_SECRET'));

// Which onPremisesExtensionAttributes slot carries the role JSON
// consumed by the downstream application's SSO layer.
define('ROLE_ATTRIBUTE', 'extensionAttribute1');

// Set true to pull last sign-in data (signInActivity). Requires the
// AuditLog.Read.All application permission (admin-consented) AND an
// Entra ID P1 license in the tenant — without both, the users query
// returns 403 and the grid shows a sync error. Flip this on only after
// granting the permission, then hit Refresh.
define('INCLUDE_SIGNIN_ACTIVITY', true);

// Grid flag heuristic: only flag an account when its most recent sign-in
// ATTEMPT (which includes failed/blocked ones) is both newer than its last
// SUCCESSFUL sign-in and happened within this many days. Keeps stale, long-
// past failures from raising a false alarm.
define('FLAG_RECENT_DAYS', 14);

// SKU GUIDs to hide from the license grid: free/self-service plans,
// trials, service plans, and SKUs with nothing purchased or assigned.
define('IGNORED_SKUS', [
    'f30db892-07e9-47e9-837c-80727f46fd3d', // FLOW_FREE            — Power Automate (free self-service)
    '5b631642-bd26-49fe-bd20-1daaa972ef80', // POWERAPPS_DEV        — Power Apps for Developer (free)
    'a403ebcc-fae0-4ca2-8c8c-7a907fd6c235', // POWER_BI_STANDARD    — Power BI (free)
    '3f9f06f5-3c31-472c-985f-62d9c10ec167', // Power_Pages_vTrial   — Power Pages trial
    '8c4ce438-32a7-4ac5-91a6-e22ae08d9c8b', // RIGHTSMANAGEMENT_ADHOC — service plan, 0 assigned
    'f8a1db68-be16-40ed-86d5-cb42ce701560', // POWER_BI_PRO         — 0 purchased / 0 assigned
    'c5928f49-12ba-48f7-ada3-0d743a3601d5', // VISIOCLIENT          — 0 purchased / 0 assigned
    '6470687e-a428-4b7a-bef2-8a291ad947c9', // WINDOWS_STORE        — 0 purchased / 0 assigned
]);

// Friendly display names per SKU GUID. Anything not listed here falls back
// to Microsoft's skuPartNumber. Names use Microsoft's current product
// branding — note SPB is Business Premium while O365_BUSINESS_PREMIUM is
// actually Business Standard (a legacy naming quirk).
define('LICENSE_NAMES', [
    'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46' => 'Biz Premium',   // SPB
    '4b9405b0-7788-4568-add1-99614e613b69' => 'Exchange P1',   // EXCHANGESTANDARD
    '4ef96642-f096-40de-a3e9-d83fb2f90211' => 'Defender P2',   // ATP_ENTERPRISE
    '19ec0d23-8335-4cbd-94ac-6050e30712fa' => 'Exchange P2',   // EXCHANGEENTERPRISE
    'c1d032e0-5619-4761-9b5c-75b6831e1711' => 'Power BI PU',   // PBI_PREMIUM_PER_USER
    'f245ecc8-75af-4f8e-b61f-27d8114de5f3' => 'Biz Standard',  // O365_BUSINESS_PREMIUM
    '53818b1b-4a27-454b-8896-0dba576410e6' => 'Project P3',    // PROJECTPROFESSIONAL
    '50f60901-3181-4b75-8a2c-4c8e4c1d5a72' => 'M365 F1',       // M365_F1_COMM
    '639dec6b-bb19-468b-871c-c5c441c4b0cb' => 'Copilot',       // Microsoft_365_Copilot
]);

// ---------------------------------------------------------------
// File cache — avoids re-querying Graph on every page load.
// ---------------------------------------------------------------
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_TTL_USERS', 900);   // 15 minutes
define('CACHE_TTL_SKUS', 3600);   // 60 minutes

function cachePath(string $key): string {
    return CACHE_DIR . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.json';
}

/** Return decoded cache value if present and younger than $ttl seconds, else null. */
function cacheGet(string $key, int $ttl) {
    $file = cachePath($key);
    if (is_readable($file) && (time() - filemtime($file)) < $ttl) {
        $data = json_decode(file_get_contents($file), true);
        return $data === null ? null : $data;
    }
    return null;
}

function cacheSet(string $key, $value): void {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0700, true);
    file_put_contents(cachePath($key), json_encode($value), LOCK_EX);
}

/** Seconds since $key was cached, or null if not cached. */
function cacheAge(string $key): ?int {
    $file = cachePath($key);
    return is_readable($file) ? time() - filemtime($file) : null;
}

/** Return cached value or run $producer, cache its result, and return it. */
function cacheRemember(string $key, int $ttl, callable $producer) {
    $hit = cacheGet($key, $ttl);
    if ($hit !== null) return $hit;
    $value = $producer();
    cacheSet($key, $value);
    return $value;
}

/** Format an age in seconds as a short human string, e.g. "3 min". */
function humanAge(int $seconds): string {
    if ($seconds < 60)   return 'just now';
    $min = intdiv($seconds, 60);
    if ($min < 60)       return $min . ' min';
    $hr = intdiv($min, 60);
    return $hr . ' hr' . ($hr > 1 ? 's' : '');
}

/** Delete a single cache entry. */
function cacheForget(string $key): void {
    $file = cachePath($key);
    if (is_file($file)) @unlink($file);
}

/** Delete all cached data (token, users, skus) — used by the Refresh action. */
function cacheClear(): void {
    foreach (['token', 'users_v2', 'users', 'skus', 'usage_D90', 'mailbox_D90', 'failures', 'admins', 'legacy'] as $key) {
        $file = cachePath($key);
        if (is_file($file)) @unlink($file);
    }
}

// ---------------------------------------------------------------
// Known-IP store — portable SQLite file (no server). The DB path is
// DB_SQLITE_PATH in .env, defaulting to ./data/known_ips.sqlite. The
// table is created on first use.
// ---------------------------------------------------------------
function db(): ?PDO {
    static $pdo = false;   // false = not yet tried, null = unavailable
    if ($pdo !== false) return $pdo;
    $path = getenv('DB_SQLITE_PATH') ?: 'data/known_ips.sqlite';
    if ($path[0] !== '/') $path = __DIR__ . '/' . $path;   // resolve relative to project
    try {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS known_ips (
            ip         TEXT PRIMARY KEY,
            label      TEXT NOT NULL DEFAULT '',
            type       TEXT NOT NULL DEFAULT 'other',
            city       TEXT NOT NULL DEFAULT '',
            state      TEXT NOT NULL DEFAULT '',
            country    TEXT NOT NULL DEFAULT '',
            isp        TEXT NOT NULL DEFAULT '',
            category   TEXT NOT NULL DEFAULT 'other',
            notes      TEXT NOT NULL DEFAULT '',
            added_by   TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        // Migrate older tables that predate newer columns
        $cols = [];
        foreach ($db->query('PRAGMA table_info(known_ips)') as $c) $cols[] = $c['name'];
        foreach (['type' => "'other'", 'city' => "''", 'state' => "''", 'country' => "''", 'isp' => "''", 'category' => "'other'"] as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $db->exec("ALTER TABLE known_ips ADD COLUMN $col TEXT NOT NULL DEFAULT $def");
            }
        }
        // Distinct users seen per IP (populated at harvest) so the modal is instant
        $db->exec("CREATE TABLE IF NOT EXISTS ip_users (
            ip        TEXT NOT NULL,
            upn       TEXT NOT NULL,
            name      TEXT NOT NULL DEFAULT '',
            logins    INTEGER NOT NULL DEFAULT 0,
            last_seen TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (ip, upn)
        )");
        return $pdo = $db;
    } catch (Throwable $e) {
        return $pdo = null;
    }
}

// Allowed IP types (single source of truth for the dropdowns). 'ignore' is
// for transient/irrelevant sources (hotels, guest houses, travel Wi-Fi).
const IP_TYPES = ['plant', 'home', 'customer', 'vendor', 'travel', 'ignore', 'other'];

// Connection categories (how the IP reaches the internet).
const IP_CATEGORIES = ['company', 'landline', 'mobile', 'satellite', 'other'];

/** Best-effort connection category from the IP, provider (ISP/ASN) and type. */
function deriveCategory(string $ip, string $isp, string $type): string {
    if ($type === 'plant') return 'company';
    $asn = preg_match('#\(AS(\d+)\)#', $isp, $m) ? (int) $m[1] : 0;
    $cellular = [6167, 21928, 20057];          // Verizon Wireless, T-Mobile, AT&T Mobility
    if (in_array($asn, $cellular, true) || ($asn === 7018 && strpos($ip, ':') !== false)) return 'mobile';
    if ($asn === 14593) return 'satellite';    // Starlink
    if ($isp === '' || $isp === 'Not routed' || $asn === 36180) return 'other';
    return 'landline';
}

/** Full known-IP records: ip => ['label','type','city','state','country']. */
function knownIpsDetailed(): array {
    $out = [];
    $db = db();
    if ($db) {
        foreach ($db->query('SELECT ip, label, type, city, state, country, isp, category FROM known_ips') as $row) {
            $out[$row['ip']] = [
                'label'    => $row['label'] !== '' ? $row['label'] : 'known',
                'type'     => $row['type'] !== '' ? $row['type'] : 'other',
                'city'     => $row['city'],
                'state'    => $row['state'],
                'country'  => $row['country'],
                'isp'      => $row['isp'],
                'category' => $row['category'] !== '' ? $row['category'] : 'other',
            ];
        }
    }
    return $out;
}

/** Format a location as "City, State, Country" (skips blanks). */
function fmtLocation(string $city, string $state, string $country): string {
    return implode(', ', array_filter([$city, $state, $country])) ?: '—';
}

/** Store/refresh the geo location for a known IP (no-op if the row is absent). */
function updateIpLocation(string $ip, string $city, string $state, string $country): void {
    $db = db();
    if (!$db) return;
    $db->prepare('UPDATE known_ips SET city=:c, state=:s, country=:co WHERE ip=:ip')
       ->execute([':c' => $city, ':s' => $state, ':co' => $country, ':ip' => $ip]);
}

/** Store/refresh the provider (ISP/ASN org) for a known IP. */
function updateIpProvider(string $ip, string $isp): void {
    $db = db();
    if (!$db) return;
    $db->prepare('UPDATE known_ips SET isp=:isp WHERE ip=:ip')->execute([':isp' => $isp, ':ip' => $ip]);
}

/** Byte-wise lower bound over a sorted array of packed-IP strings. */
function lowerBoundStr(array $sorted, string $target): int {
    $lo = 0; $hi = count($sorted);
    while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if (strcmp($sorted[$mid], $target) < 0) $lo = $mid + 1; else $hi = $mid; }
    return $lo;
}

/**
 * OFFLINE provider lookup — maps IPs to their ISP/ASN org by streaming the
 * local iptoasn dataset (ip2asn-combined.tsv.gz). No data leaves the machine.
 * Returns ip => "ORG DESCRIPTION (ASxxxx)". Empty if the dataset is missing.
 */
function lookupProviders(array $ips): array {
    $path = getenv('IP2ASN_PATH') ?: (__DIR__ . '/ip2asn-combined.tsv.gz');
    $out = [];
    if (!is_readable($path)) return $out;

    $v4 = []; $v6 = []; $map = [];
    foreach (array_unique($ips) as $ip) {
        $p = @inet_pton($ip);
        if ($p === false) continue;
        $map[$p] = $ip;
        if (strlen($p) === 4) $v4[] = $p; else $v6[] = $p;
    }
    if (!$v4 && !$v6) return $out;
    usort($v4, 'strcmp');
    usort($v6, 'strcmp');
    $n4 = count($v4); $n6 = count($v6);

    $gz = gzopen($path, 'rb');
    if (!$gz) return $out;
    while (($line = gzgets($gz)) !== false) {
        $parts = explode("\t", rtrim($line, "\r\n"));
        if (count($parts) < 5) continue;
        $startP = @inet_pton($parts[0]);
        if ($startP === false) continue;
        $is4 = strlen($startP) === 4;
        $fam = $is4 ? $v4 : $v6;
        $cnt = $is4 ? $n4 : $n6;
        if (!$cnt) continue;
        $endP = @inet_pton($parts[1]);
        if ($endP === false) continue;
        for ($i = lowerBoundStr($fam, $startP); $i < $cnt; $i++) {
            if (strcmp($fam[$i], $endP) > 0) break;
            $asn = $parts[2];
            $desc = $parts[4] !== '' ? $parts[4] : ('AS' . $asn);
            $out[$map[$fam[$i]]] = $desc . ($asn && $asn !== '0' ? " (AS$asn)" : '');
        }
    }
    gzclose($gz);
    return $out;
}

/** Map of ip => label for all known IPs. */
function knownIps(): array {
    return array_map(fn($r) => $r['label'], knownIpsDetailed());
}

/** Map of ip => label for known IPs of a given type (e.g. 'plant'). */
function knownIpsOfType(string $type): array {
    $out = [];
    foreach (knownIpsDetailed() as $ip => $r) if ($r['type'] === $type) $out[$ip] = $r['label'];
    return $out;
}

/** Save (or update) a known IP with a type. Returns true on success. */
function addKnownIp(string $ip, string $label, string $type = 'other', string $addedBy = ''): bool {
    $db = db();
    if (!$db) return false;
    if (!in_array($type, IP_TYPES, true)) $type = 'other';
    $stmt = $db->prepare('INSERT INTO known_ips (ip, label, type, added_by) VALUES (:ip, :label, :type, :by)
        ON CONFLICT(ip) DO UPDATE SET label = excluded.label, type = excluded.type');
    return $stmt->execute([':ip' => $ip, ':label' => $label, ':type' => $type, ':by' => $addedBy]);
}

/** Set the connection category for a known IP. */
function updateIpCategory(string $ip, string $category): void {
    $db = db();
    if (!$db) return;
    if (!in_array($category, IP_CATEGORIES, true)) $category = 'other';
    $db->prepare('UPDATE known_ips SET category = :c WHERE ip = :ip')->execute([':c' => $category, ':ip' => $ip]);
}

/**
 * Bulk-set fields on many IPs. Only non-null args are applied.
 * Returns rows changed.
 */
function bulkUpdateIps(array $ips, ?string $type = null, ?string $label = null, ?string $category = null): int {
    $db = db();
    $ips = array_values(array_filter(array_map('trim', $ips)));
    if (!$db || !$ips) return 0;
    $set = []; $args = [];
    if ($type !== null && $type !== '')  { $set[] = 'type = ?';     $args[] = in_array($type, IP_TYPES, true) ? $type : 'other'; }
    if ($label !== null && $label !== '') { $set[] = 'label = ?';   $args[] = $label; }
    if ($category !== null && $category !== '') { $set[] = 'category = ?'; $args[] = in_array($category, IP_CATEGORIES, true) ? $category : 'other'; }
    if (!$set) return 0;
    $ph = implode(',', array_fill(0, count($ips), '?'));
    $st = $db->prepare('UPDATE known_ips SET ' . implode(', ', $set) . " WHERE ip IN ($ph)");
    $st->execute(array_merge($args, $ips));
    return $st->rowCount();
}

/** Remove a known IP. Returns true on success. */
function removeKnownIp(string $ip): bool {
    $db = db();
    if (!$db) return false;
    return $db->prepare('DELETE FROM known_ips WHERE ip = :ip')->execute([':ip' => $ip]);
}

/** Replace the stored distinct-user list for an IP. $users: upn => [name,count,last]. */
function storeIpUsers(string $ip, array $users): void {
    $db = db();
    if (!$db) return;
    $db->beginTransaction();
    $db->prepare('DELETE FROM ip_users WHERE ip = :ip')->execute([':ip' => $ip]);
    $ins = $db->prepare('INSERT INTO ip_users (ip, upn, name, logins, last_seen) VALUES (:ip,:upn,:name,:n,:last)');
    foreach ($users as $upn => $d) {
        $ins->execute([':ip' => $ip, ':upn' => $upn, ':name' => $d['name'], ':n' => $d['count'], ':last' => $d['last']]);
    }
    $db->commit();
}

/** Stored distinct users for an IP (from the last harvest), most active first. */
function ipUsersFromDb(string $ip): array {
    $db = db();
    if (!$db) return [];
    $st = $db->prepare('SELECT upn, name, logins, last_seen FROM ip_users WHERE ip = :ip ORDER BY logins DESC');
    $st->execute([':ip' => $ip]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------
// HTTP helper
// ---------------------------------------------------------------
function graphRequest(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,   // reports endpoints 302 to a data URL
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);

    if ($response === false) {
        throw new RuntimeException("HTTP request to $url failed: $curlErr");
    }
    if ($status >= 400) {
        throw new RuntimeException("Request to $url returned HTTP $status: $response");
    }
    return [
        'status' => $status,
        'body'   => $response === '' ? [] : json_decode($response, true),
        'raw'    => $response,
    ];
}

/**
 * Step A: Acquire OAuth2 Access Token from Microsoft Token Endpoint.
 * Cached to disk (and memoized per request) until it actually expires,
 * with a 60-second safety margin, so repeated page loads reuse one token.
 */
function getEntraAccessToken(): string {
    static $mem = null;
    if ($mem !== null) return $mem;

    // Disk cache keyed on expiry rather than file age
    $file = cachePath('token');
    if (is_readable($file)) {
        $cached = json_decode(file_get_contents($file), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $mem = $cached['access_token'];
        }
    }

    $url  = 'https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0/token';
    $data = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'scope'         => 'https://graph.microsoft.com/.default',
    ]);

    $result = graphRequest('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], $data);
    $token  = $result['body']['access_token'] ?? null;
    if (!$token) {
        throw new RuntimeException('Token endpoint did not return an access_token. Check tenant/client/secret in .env.');
    }
    $expiresIn = (int) ($result['body']['expires_in'] ?? 3600);
    cacheSet('token', ['access_token' => $token, 'expires_at' => time() + $expiresIn]);
    return $mem = $token;
}

/**
 * Step B: Query the Graph API for Users, Licenses, and Extension Attributes.
 * Follows @odata.nextLink so tenants with more than one page (100 users)
 * are fully returned.
 */
function fetchEntraUsers(string $token, bool $includeSignin = INCLUDE_SIGNIN_ACTIVITY): array {
    $select = [
        'id', 'displayName', 'userPrincipalName', 'mail', 'accountEnabled',
        'assignedLicenses', 'onPremisesExtensionAttributes', 'userType',
        'onPremisesSyncEnabled', 'createdDateTime', 'lastPasswordChangeDateTime',
        'jobTitle', 'department', 'companyName', 'officeLocation',
        'usageLocation', 'mobilePhone', 'businessPhones', 'employeeId',
    ];
    if ($includeSignin) {
        $select[] = 'signInActivity';
    }

    $users = [];
    $url = 'https://graph.microsoft.com/v1.0/users'
         . '?$select=' . implode(',', $select)
         . '&$top=999';

    while ($url) {
        $result = graphRequest('GET', $url, ["Authorization: Bearer $token"]);
        $users  = array_merge($users, $result['body']['value'] ?? []);
        $url    = $result['body']['@odata.nextLink'] ?? null;
    }
    return $users;
}

/**
 * Fetch every license SKU the tenant is subscribed to.
 */
function fetchSubscribedSkus(string $token): array {
    $result = graphRequest('GET', 'https://graph.microsoft.com/v1.0/subscribedSkus', ["Authorization: Bearer $token"]);
    return $result['body']['value'] ?? [];
}

/**
 * Fetch recent sign-in log entries for one user (most recent first).
 * Requires AuditLog.Read.All + Entra ID P1. Returns [] on none.
 */
function fetchUserSignIns(string $token, string $upn, int $top = 50): array {
    $safe = str_replace("'", "''", $upn);        // OData single-quote escaping
    $url = 'https://graph.microsoft.com/v1.0/auditLogs/signIns'
         . '?$top=' . (int) $top
         . '&$filter=' . rawurlencode("userPrincipalName eq '$safe'");
    $result = graphRequest('GET', $url, ["Authorization: Bearer $token"]);
    return $result['body']['value'] ?? [];
}

/**
 * Fetch the Microsoft 365 per-user activity report (Exchange/Teams/
 * SharePoint/OneDrive last-activity dates). Requires Reports.Read.All.
 * Returns a map keyed by lowercased UPN. Note: report data is typically
 * 1-2 days behind, and only covers core M365 services (not Power BI,
 * Project, Visio, etc.).
 */
function fetchUsageReport(string $token, string $period = 'D90'): array {
    // This report only serves CSV (no JSON format), so parse the CSV.
    $url = "https://graph.microsoft.com/v1.0/reports/getOffice365ActiveUserDetail(period='" . $period . "')";
    $result = graphRequest('GET', $url, ["Authorization: Bearer $token"]);

    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $result['raw'] ?? '');   // strip UTF-8 BOM
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (count($lines) < 2) return [];

    $idx = array_flip(str_getcsv(array_shift($lines), ',', '"', ''));
    $col = fn(array $cols, string $name) => isset($idx[$name]) ? ($cols[$idx[$name]] ?? '') : '';

    $byUpn = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $cols = str_getcsv($line, ',', '"', '');
        $upn  = strtolower($col($cols, 'User Principal Name'));
        if ($upn === '') continue;
        $byUpn[$upn] = [
            'userPrincipalName'          => $col($cols, 'User Principal Name'),
            'reportRefreshDate'          => $col($cols, 'Report Refresh Date'),
            'exchangeLastActivityDate'   => $col($cols, 'Exchange Last Activity Date'),
            'teamsLastActivityDate'      => $col($cols, 'Teams Last Activity Date'),
            'sharePointLastActivityDate' => $col($cols, 'SharePoint Last Activity Date'),
            'oneDriveLastActivityDate'   => $col($cols, 'OneDrive Last Activity Date'),
        ];
    }
    return $byUpn;
}

/**
 * Fetch the per-mailbox usage report — mailbox-level last-activity date
 * (this counts delegated access, unlike getOffice365ActiveUserDetail),
 * item count and storage used. Requires Reports.Read.All.
 * Note: this report has no recipient-type column, so User-vs-Shared is
 * not available here — that lives in Exchange admin.
 */
function fetchMailboxUsage(string $token, string $period = 'D90'): array {
    $url = "https://graph.microsoft.com/v1.0/reports/getMailboxUsageDetail(period='" . $period . "')";
    $result = graphRequest('GET', $url, ["Authorization: Bearer $token"]);

    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $result['raw'] ?? '');
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (count($lines) < 2) return [];

    $idx = array_flip(str_getcsv(array_shift($lines), ',', '"', ''));
    $col = fn(array $cols, string $name) => isset($idx[$name]) ? ($cols[$idx[$name]] ?? '') : '';

    $byUpn = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $cols = str_getcsv($line, ',', '"', '');
        $upn  = strtolower($col($cols, 'User Principal Name'));
        if ($upn === '') continue;
        $byUpn[$upn] = [
            'lastActivityDate' => $col($cols, 'Last Activity Date'),
            'itemCount'        => (int) $col($cols, 'Item Count'),
            'storageBytes'     => (int) $col($cols, 'Storage Used (Byte)'),
        ];
    }
    return $byUpn;
}

/**
 * Fetch recent FAILED sign-ins tenant-wide (most recent first), paging up
 * to $maxPages of 999. Requires AuditLog.Read.All + Entra ID P1.
 * Returns ['events' => [...], 'capped' => bool] — capped=true means there
 * were more failures than we pulled (so counts are a floor, not a total).
 */
function fetchRecentFailures(string $token, int $maxPages = 6): array {
    $url = 'https://graph.microsoft.com/v1.0/auditLogs/signIns'
         . '?$filter=' . rawurlencode('status/errorCode ne 0') . '&$top=999';
    $events = [];
    $pages = 0;
    while ($url && $pages < $maxPages) {
        $r = graphRequest('GET', $url, ["Authorization: Bearer $token"]);
        $events = array_merge($events, $r['body']['value'] ?? []);
        $url = $r['body']['@odata.nextLink'] ?? null;
        $pages++;
    }
    return ['events' => $events, 'capped' => $url !== null];
}

/**
 * Fetch recent SUCCESSFUL sign-ins tenant-wide, paging up to $maxPages of
 * 999. Used to harvest shared/known source IPs. Requires AuditLog.Read.All.
 * Returns ['events' => [...], 'capped' => bool].
 */
function fetchRecentSuccesses(string $token, int $maxPages = 5): array {
    $url = 'https://graph.microsoft.com/v1.0/auditLogs/signIns'
         . '?$filter=' . rawurlencode('status/errorCode eq 0') . '&$top=999';
    $events = [];
    $pages = 0;
    while ($url && $pages < $maxPages) {
        $r = graphRequest('GET', $url, ["Authorization: Bearer $token"]);
        $events = array_merge($events, $r['body']['value'] ?? []);
        $url = $r['body']['@odata.nextLink'] ?? null;
        $pages++;
    }
    return ['events' => $events, 'capped' => $url !== null];
}

/**
 * Fetch recent LEGACY-auth sign-ins tenant-wide (successes + failures),
 * paging up to $maxPages of 999. Legacy = any client app that isn't the
 * modern browser or desktop/mobile stack. Requires AuditLog.Read.All + P1.
 * Returns ['events' => [...], 'capped' => bool].
 */
function fetchLegacySignIns(string $token, int $maxPages = 3): array {
    $filter = "clientAppUsed ne 'Browser' and clientAppUsed ne 'Mobile Apps and Desktop clients'";
    $url = 'https://graph.microsoft.com/v1.0/auditLogs/signIns'
         . '?$filter=' . rawurlencode($filter) . '&$top=999';
    $events = [];
    $pages = 0;
    while ($url && $pages < $maxPages) {
        $r = graphRequest('GET', $url, ["Authorization: Bearer $token"]);
        $events = array_merge($events, $r['body']['value'] ?? []);
        $url = $r['body']['@odata.nextLink'] ?? null;
        $pages++;
    }
    return ['events' => $events, 'capped' => $url !== null];
}

/**
 * Map of UPN => [role names] for every user holding a directory (admin)
 * role. Requires RoleManagement.Read.Directory (or Directory.Read.All).
 */
function fetchAdminRoles(string $token): array {
    $r = graphRequest('GET', 'https://graph.microsoft.com/v1.0/directoryRoles?$expand=members',
        ["Authorization: Bearer $token"]);
    $byUpn = [];
    foreach ($r['body']['value'] ?? [] as $role) {
        foreach ($role['members'] ?? [] as $m) {
            $upn = strtolower($m['userPrincipalName'] ?? '');
            if ($upn !== '') $byUpn[$upn][] = $role['displayName'];
        }
    }
    return $byUpn;
}

/**
 * Is this sign-in over a legacy (non-modern) auth protocol? Legacy auth
 * bypasses MFA and is the usual spray vector. Modern = browser or the
 * modern desktop/mobile client stack; everything else is legacy.
 */
function isLegacyAuth(array $signIn): bool {
    $app = $signIn['clientAppUsed'] ?? '';
    return $app !== '' && !in_array($app, ['Browser', 'Mobile Apps and Desktop clients']);
}

/**
 * Fetch recent sign-ins (success + failure) from a specific source IP,
 * to identify who has used it. Requires AuditLog.Read.All + P1.
 */
function fetchSignInsByIp(string $token, string $ip, int $top = 500): array {
    $safe = str_replace("'", "''", $ip);
    $url = 'https://graph.microsoft.com/v1.0/auditLogs/signIns'
         . '?$filter=' . rawurlencode("ipAddress eq '$safe'") . '&$top=' . (int) $top;
    $r = graphRequest('GET', $url, ["Authorization: Bearer $token"]);
    return $r['body']['value'] ?? [];
}

/** Look up a single user in the cached roster by UPN (case-insensitive). */
function findCachedUser(string $upn): ?array {
    $cached = cacheGet('users_v2', PHP_INT_MAX);   // ignore age; just want the record
    if (!is_array($cached)) return null;
    foreach ($cached as $u) {
        if (strcasecmp($u['userPrincipalName'] ?? '', $upn) === 0) return $u;
    }
    return null;
}

/**
 * Build the grid columns from the tenant's subscribed SKUs,
 * dropping anything in IGNORED_SKUS and applying friendly names.
 * Returns [skuId => displayName], sorted by display name.
 */
function licenseColumns(array $skus): array {
    $columns = [];
    foreach ($skus as $sku) {
        if (in_array($sku['skuId'], IGNORED_SKUS)) continue;
        $columns[$sku['skuId']] = LICENSE_NAMES[$sku['skuId']] ?? $sku['skuPartNumber'];
    }
    asort($columns);
    return $columns;
}

/**
 * Step C: Stamp a JSON role payload onto a user's extension attribute.
 * The downstream app's SSO layer reads this claim and parses the JSON.
 *
 * Note: only works for cloud-only users. Users synced from on-prem AD
 * have read-only onPremisesExtensionAttributes in Graph — those must be
 * set in AD and synced. Requires the app registration to hold the
 * User.ReadWrite.All *application* permission (admin-consented).
 *
 * Takes a flat list of role names and stores it as a compact JSON array,
 * e.g. ["admin","approver"] — keeps the payload well under the
 * 1024-character extension attribute limit.
 *
 * Example: setUserRoles($token, $userId, ['admin', 'approver']);
 */
function setUserRoles(string $token, string $userId, array $roles): void {
    $roles = array_values(array_unique(array_map('strval', $roles)));
    $json  = json_encode($roles, JSON_UNESCAPED_SLASHES);
    if (strlen($json) > 1024) {
        throw new InvalidArgumentException('Role list exceeds the 1024-character limit of extension attributes.');
    }

    $url  = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userId);
    $body = json_encode([
        'onPremisesExtensionAttributes' => [ROLE_ATTRIBUTE => $json],
    ]);

    graphRequest('PATCH', $url, [
        "Authorization: Bearer $token",
        'Content-Type: application/json',
    ], $body);
}

/**
 * Read back the role list for a user row already fetched by fetchEntraUsers().
 * Returns null when the attribute is empty or not valid JSON.
 */
function getUserRoles(array $user): ?array {
    $raw = $user['onPremisesExtensionAttributes'][ROLE_ATTRIBUTE] ?? null;
    if (!$raw) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

// ---------------------------------------------------------------
// Execute the pipeline (skipped when a page defines ENTRA_NO_AUTORUN
// to use the functions above à la carte, e.g. skus.php)
// ---------------------------------------------------------------
if (!defined('ENTRA_NO_AUTORUN')) {
    // ?refresh=1 forces a live re-query by clearing the cache first.
    if (isset($_GET['refresh'])) cacheClear();
    $signinWarning = null;
    try {
        $users = cacheRemember('users_v2', CACHE_TTL_USERS, function () use (&$signinWarning) {
            $token = getEntraAccessToken();
            // Try with signInActivity; if the AuditLog permission isn't effective
            // yet, fall back to fetching without it so the grid still loads.
            if (INCLUDE_SIGNIN_ACTIVITY) {
                try {
                    return fetchEntraUsers($token, true);
                } catch (RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'AuditLog.Read.All')) {
                        $signinWarning = 'Sign-in data unavailable: the app does not yet have an effective '
                            . 'AuditLog.Read.All permission. Grant admin consent (and allow a few minutes to '
                            . 'propagate), then Refresh.';
                        return fetchEntraUsers($token, false);
                    }
                    throw $e;
                }
            }
            return fetchEntraUsers($token, false);
        });
        $skus = cacheRemember('skus', CACHE_TTL_SKUS,
            fn() => fetchSubscribedSkus(getEntraAccessToken()));
        $licenseColumns = licenseColumns($skus);
        $dataAge = cacheAge('users_v2');   // seconds since users were fetched
        $syncError = null;
    } catch (Throwable $e) {
        $users = [];
        $licenseColumns = [];
        $dataAge = null;
        $syncError = $e->getMessage();
    }
}
