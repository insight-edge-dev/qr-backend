# Security policy

## Reporting

Report vulnerabilities privately to the repository owners. Do not publish credentials, tokens, personal data, QR payloads, database contents, infrastructure details, or exploit instructions in a public issue.

If a secret may have entered Git, stop publishing and revoke or rotate it through the owning system before history remediation. Never paste the value into an issue, commit, test log, or chat transcript.

## Repository rules

- Never commit runtime configuration, `.env` files, raw or hashed operational tokens, database credentials, private keys, SQL backups, logs, production data, or personal data.
- Keep the web document root restricted to `public/`.
- Keep `reference/`, local evidence, machine-specific reports, and production backups out of published history.
- Retain the fail-closed staging environment, host, loopback database, and database-name guards.
- Require HTTPS and normal certificate verification. Never trust client-supplied forwarding headers without a separately reviewed trusted proxy configuration.
- CORS remains an exact allowlist; do not introduce wildcard origins or credentialed browser access casually.
- Preserve strict bearer-token validation, expiry, revocation, role checks, and constant-time digest comparison.
- Preserve prepared SQL, bounded pagination, body limits, safe errors, and atomic scan/audit transactions.
- Destructive, employee, attendance, dashboard, and export operations remain disabled until separately specified and approved.
- The runtime database identity must not have DELETE, UPDATE, DDL, user-management, or unrelated-schema access.

## Compatibility and release gates

Before deployment, validate the exact target versions—currently expected to be PHP 8.3 and MariaDB 10.11—in a disposable environment. Prior PHP 8.4/MariaDB 11.8 results do not prove target-host compatibility.

Complete lint, in-memory security tests, static checks, isolated database tests, private HTTPS tests, secret scanning, configuration review, least-privilege verification, backup/rollback review, and final staged-diff review. Do not publish or deploy when any required check is unavailable or failing.

Detailed controls are documented in [docs/SECURITY.md](docs/SECURITY.md).
