# entratrak

Internal Entra ID (Azure AD) admin dashboard for Southland Steel — user/license
grid, sign-in diagnostics, attack surveillance, legacy-auth review, license
utilization, and a known-IP classifier. Plain PHP + SQLite, no framework.

## Pages
- **index.php** — dashboard
- **entra.php** — user / license grid (filters, per-user modal)
- **skus.php** — tenant license SKUs
- **usage.php** — license utilization (M365 activity → reclaim candidates)
- **signins.php** — per-user sign-in diagnostics + successful-sign-in summary
- **attacks.php** — accounts ranked by failed sign-ins (spray detection)
- **legacy.php** — legacy-auth (MFA-bypassing) usage by site
- **known-ips.php** — harvest & classify source IPs (type, category, provider)

## Setup

1. **Clone**, then `cp .env.example .env` and fill in the Entra app-registration
   values (`ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET`).

2. **Graph permissions** (Application, admin-consented) on the app registration:
   - `Directory.Read.All` (users, admin roles) — read-only
   - `Organization.Read.All` (subscribed SKUs)
   - `AuditLog.Read.All` (sign-in logs — needs Entra ID P1)
   - `Reports.Read.All` (M365 usage / mailbox reports)
   - Add `User.ReadWrite.All` only if you enable writing role JSON to a user
     attribute (`setUserRoles()`); the app is otherwise read-only.

3. **Provider lookup (offline).** Download the free combined IP→ASN dataset from
   <https://iptoasn.com> (`ip2asn-combined.tsv.gz`) into the project root. It is
   gitignored (regenerable third-party data). Without it, provider columns are
   blank; everything else still works.

4. **Database (SQLite).** Auto-creates on first load. To seed the company plant
   IPs so the Legacy rollup works immediately:
   ```
   sqlite3 data/known_ips.sqlite < seed.sql
   ```
   Then open **known-ips.php** and click **Harvest** to populate the rest from
   sign-in data, and classify each IP (type + category). The harvested DB holds
   internal data (employee home IPs, user↔IP mappings) and is gitignored — each
   install builds its own.

5. **Run:** `php -S localhost:8005 -t .` and open http://localhost:8005

## Not in the repo (gitignored)
`.env` (secrets), `data/` (SQLite DB), `cache/` (Graph response cache),
`ip2asn-combined.tsv.gz` (provider dataset).
