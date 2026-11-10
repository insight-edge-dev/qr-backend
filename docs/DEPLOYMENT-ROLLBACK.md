# Deployment prerequisites and rollback — no actions performed

## Before any deployment
- Obtain answers in BUSINESS-RULES.md and review the entire diff against inert reference endpoints.
- Approve PHP runtime installation/provisioning separately. Candidate is a current patched PHP 8.4,
  with MySQLi/mysqlnd, JSON and CSPRNG/hash support; no Composer dependencies are introduced.
- Approve an isolated host/account and compatible MariaDB, with no production connectivity.
  Code pins loopback DB and the new qr_staging name. Use InnoDB, utf8mb4 and UTC.
- Approve fresh DB/schema initialization; never run with --force or against an existing database.
  initialize-staging.sql intentionally has no IF NOT EXISTS, DROP/TRUNCATE/DELETE, users or GRANTs.
  It creates the original three tables, an audit_events table, TWO synthetic employees and THREE
  synthetic scans. lunch_scans/audit_events start empty. It was not imported or SQL-runtime-validated.
- Provision a distinct non-production runtime DB account with only SELECT/INSERT on random_scans
  and INSERT on audit_events; separate DDL operator, no deletion/employee-write permissions.
- Provision external owner-only config with actual staging DB credentials and an approved hashed-token
  registry. Do not place the config example into service unchanged: it is intentionally fail-closed.
- The token issuer must use 32 CSPRNG bytes/base64url, record only its SHA-256/identity/role/expiry,
  distribute the raw token once through an approved secure channel and retain a revocation process.
  No issuing command has been run and no operator identity or credential is chosen by this preparation.
- Configure clock synchronization, private logs, TLS/certificates, FPM identity, ingress rate limiting
  and allowed tester network. Select the qr-staging.* host explicitly.
- Serve ONLY public/. Reference/evidence/config/src/database/tests/docs and .gitignore are not web content.
  Never restore unrelated API/WordPress/uploads/service-account files.
- Review/adapt the inactive Nginx template; it is not a deploy script. No Windows settings were changed.
- Complete PHP lint/unit tests and the approved isolated integration tests before any APK launch.

## Schema notes
employees and lunch_scans preserve the original relationship and field sizes, without changing attendance.
random_scans remains independent: no employee FK or deduplication rule is invented.
audit_events is a staging security table for atomic successful insert audit records, not an attendance log.
UTC here is a technical staging baseline; business-day/attendance logic is disabled pending agreement.

## Rollback
No live rollback is needed: nothing was installed/deployed/imported and no APK was rebuilt.
Keep the original archives/Downloads/Flutter projects unchanged.
If this preparation is rejected, retain evidence, then archive or remove ONLY backend-staging after
checking its resolved path and obtaining explicit deletion approval. Do not delete QRScanner or shared SDKs.
No removal or cleanup command is provided or executed here.

For a later approved deployment, use versioned staging copies and an atomic service/config switch.
Take a synthetic DB snapshot before migrations. On failure stop ingress, revoke staging tokens,
roll back the deployed version/config and restore ONLY the disposable staging database after approval.
Never point the staging config at production as a fallback. Keep production keys/accounts out of rollback.
