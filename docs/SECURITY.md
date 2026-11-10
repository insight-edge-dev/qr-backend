# Security architecture

## Staging-only authentication
Use individual, high-entropy opaque bearer tokens; never a shared hardcoded APK API key.
Each token must represent 32 cryptographically random bytes encoded as unpadded base64url (43 characters).
An authorized operator must provision identities/tokens through an approved secure channel LATER.
This preparation creates no operational token, password, user account or token record.

The external registry stores only lowercase SHA-256(token), subject, role, issued_at,
expires_at and revoked. Times are integer Unix UTC seconds; lifetime <=8 hours.
The server computes the presented token hash and compares with hash_equals, checks validity,
revocation and allowed endpoint role on every data request. Registry entries and roles are never
taken from request data. Duplicate hashes/unknown roles/invalid configuration fail closed.

Registry record field schema (not an actual credential):
- sha256: 64 lowercase hex characters, derived from an approved random token
- subject: non-personal operator identifier matching A-Z/a-z/0-9/_/- (1..64)
- role: scanner OR administrator
- issued_at, expires_at: integer UTC seconds; positive lifetime <=28800 seconds
- revoked: JSON boolean

No token may be placed in URLs, logs, Git, QR payloads, config examples or source code.
Store the raw token only on the operator's approved device/secure provisioning channel.
A bearer token is possession-based: use short expiry, per-operator issuance, revocation and HTTPS.
Registry reload on each request permits revocation by an approved atomic config replacement.
No refresh/login/password/MFA identity provider has been invented; choose the long-term provider separately.
Do not confuse this bounded staging scheme with a production identity-management implementation.

## Roles and disabled operations
| Operation | scanner | administrator |
| --- | --- | --- |
| Insert opaque scan | yes | no |
| List paginated scans | no | yes |
| whoami | yes | yes |
| Delete one/all scans | forbidden | disabled (501) |
| Employee create/dashboard | forbidden | disabled (501) |
| Employee attendance submit | disabled (501) | forbidden |

No database connection happens before transport/host/origin/method, authentication, authorization
and input checks. Disabled operations never open a DB connection. There is no DELETE SQL or employee
mutation in the new runtime store; old code is inert reference text outside the webroot.

## Configuration and storage boundary
Copy the empty example only after approval to an external secret path such as /etc/qr-staging/config.json.
Set QR_STAGING_CONFIG in a dedicated PHP-FPM pool. Never serve the repository root.
The loader resolves paths and refuses files under public/. On Linux group/other file permissions
must be zero (e.g. owner-only 0600). Use a dedicated service account and restrictive directory permissions.
Real config files are ignored by .gitignore; do not put secrets in source control even if ignored.

Required guards: environment=staging, a qr-staging.* hostname, exact allowed host, only local DB
127.0.0.1/localhost:3306, and database name exactly qr_staging. Real credentials and a valid registry
must be explicitly provisioned; the example returns 503. Hostname naming is an intentional deployment
guard, not proof of network isolation. Dedicated host/DB accounts and production egress restrictions
remain mandatory; do not point a local DB proxy at production.

MySQLi uses strict errors, utf8mb4 and UTC. Queries bind all variable values. Successful scan insertion
and its audit_events entry commit together; a failed audit insert rolls back the scan. Runtime logs
contain only generic event/class/request-ID information, not raw exception messages or QR contents.
Client errors use safe JSON/status codes and no-store/nosniff headers. A private PHP log destination
must be provisioned before deployment. Auth/access-denial monitoring is an ingress/logging prerequisite;
do not configure access logs to record Authorization or request bodies.

Runtime DB account: SELECT/INSERT on random_scans; INSERT on audit_events. No DELETE, UPDATE,
DDL, employee writes or administration privilege is needed while ambiguous features are disabled.
Use a separate schema-initialization operator; never embed a GRANT or password in the SQL script.

## Transport and browser boundary
PHP requires server HTTPS=on/1. It intentionally ignores X-Forwarded-Proto from request headers.
With a trusted TLS edge, configure the dedicated server/FastCGI environment to represent verified TLS;
never trust arbitrary client-forwarded values. Deny non-TLS ingress rather than redirecting bearer tokens.
The inactive Nginx template forwards Authorization and sets HTTPS on only in its TLS listener.

CORS: empty by default; otherwise exact configured HTTPS origins, no wildcard or cookies.
Preflight permits only the route's method and Authorization/Content-Type. It is metadata-only.
Apply request-rate limits at the private ingress (template 5 requests/second/IP, burst 10), reasonable
timeouts, and private tester/VPN access. The PHP application does not implement a distributed limiter.
Provisioning/running Nginx and testing rate limits require separate approval.

## References
- https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html
- https://www.php.net/manual/en/function.hash-equals.php
- https://www.php.net/manual/en/function.random-bytes.php
