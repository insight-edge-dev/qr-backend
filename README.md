# QR backend — isolated staging API

Private PHP staging API for authenticated QR scan insertion and read-only administration. This repository is not a production deployment and contains no operational credentials, tokens, production records, or database backup.

## Implemented API

| Endpoint | Method | Role | State |
| --- | --- | --- | --- |
| `/auth/whoami.php` | GET | scanner or administrator | enabled |
| `/qr/save_scan.php` | POST | scanner | enabled |
| `/qr/get_random_scans.php` | GET | administrator | enabled, cursor-paginated |
| deletion, employee, attendance and dashboard routes | fixed route method | role-checked | disabled with `501` |

Only `public/` may be configured as the document root. Source, configuration, tests, documentation, and the synthetic schema must remain outside the public webroot.

## Security model

- HTTPS, an exact allowed host, and exact optional CORS origins are enforced before database access.
- Opaque 43-character bearer tokens are stored only as SHA-256 digests in an external owner-only configuration file.
- Tokens carry a `scanner` or `administrator` role, validity window, maximum eight-hour lifetime, and revocation state.
- Variable SQL uses prepared MySQLi statements. Scan insertion and audit insertion commit atomically.
- Requests have strict methods, content types, body size, input validation, pagination bounds, and safe JSON errors.
- The runtime database account needs only `SELECT, INSERT` on `random_scans` and `INSERT` on `audit_events`.
- There is no destructive SQL, employee mutation, attendance implementation, token issuer, or authentication bypass.

See [SECURITY.md](SECURITY.md) and [docs/SECURITY.md](docs/SECURITY.md) for the full threat boundary.

## Local verification

Requirements: patched PHP 8.3 or 8.4 CLI with MySQLi/mysqlnd and JSON. PHP 8.4 and MariaDB 11.8 were used for prior isolated validation; compatibility with the intended VPS baseline of PHP 8.3 and MariaDB 10.11 remains a release gate.

```powershell
php -l src/Core.php
php -l src/App.php
php -l src/MysqliStore.php
php -l src/bootstrap.php
php tests/run.php
powershell -File tests/static-checks.ps1
```

Lint every PHP file under `src/`, `public/`, and `tests/`. The in-memory suite makes no HTTP or database connections. Database and HTTP tests must target only an approved disposable local environment using synthetic records.

## Configuration and deployment

`config/staging.example.json` is intentionally unusable. Create the real configuration outside the repository and public webroot, set `QR_STAGING_CONFIG` in a dedicated PHP-FPM pool, restrict file permissions, and provision tokens through a separate approved secure channel. Never commit the generated file or raw tokens.

`database/initialize-staging.sql` creates only `qr_staging` and synthetic fixtures. Review it before use and never import production backups. `deployment/nginx.example.conf` is a review template, not an automated deployment script.

Follow [docs/DEPLOYMENT-ROLLBACK.md](docs/DEPLOYMENT-ROLLBACK.md). No public deployment, production connection, or destructive operation is authorized by this repository.
