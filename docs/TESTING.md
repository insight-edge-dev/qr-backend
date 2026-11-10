# Verification status and test plan
PHP is not available in PATH or inspected standard locations. No PHP/MySQL/Docker was installed.
PHP lint and tests/run.php: NOT EXECUTED. No HTTP server, SQL import or database test was run.
Static packaging checks cannot establish PHP syntax/runtime correctness, SQL import success or security completeness.

## Prepared non-destructive tests
tests/run.php needs only an approved PHP 8.4 CLI; it loads Core/App, not the DB/bootstrap.
It uses in-memory MemoryStore and ephemeral synthetic tokens generated only during an explicit test run.
No curl/HTTP/DB calls, imports, filesystem credential files, production URLs or real QR/employee rows.
No operational credential is included in tests or sample config.

Cases cover:
- valid/missing/invalid/expired/not-yet-valid/revoked bearer tokens;
- scanner vs administrator authorization and verified whoami;
- GET/PUT misuse and state-mutating legacy GET rejection;
- mandatory HTTPS, untrusted forwarded proto, allowed Host;
- JSON/media-type/shape/type/UTF-8/NUL/size checks, opaque content preservation;
- default/capped/keyset pagination, array/unknown/overflow query parameters;
- unauthorized deletion, scanner denial and authenticated admin 501/no DB connection;
- all ambiguous employee/attendance routes disabled without DB access;
- exact-origin CORS, null/unknown origins and metadata-only preflight restrictions;
- safe connection/insert/read failure responses, no sensitive exception message;
- empty registry, production environment, wrong/remote DB and malformed policy fail closed.

## Commands only after runtime approval
From this workspace, with a supported patched PHP CLI explicitly approved:
    php -l src/Core.php
    php -l src/App.php
    php -l src/MysqliStore.php
    php -l src/bootstrap.php
    php tests/run.php
Lint all remaining public/tests PHP files as well. Expected unit success exit is 0, but no result is claimed.
Do not run the server as a substitute for unavailable tests.

## Deferred isolated integration checks
After separate host/database/import/deployment/test approval:
- Verify actual TLS/FastCGI Authorization propagation and HTTPS server flag, restricted docroot,
  absence of reference/config/SQL/key downloads, disabled cleartext ingress and private access.
- Verify log redaction and rate limiting (including request bursts/429) without logging credentials.
- Create a disposable local qr_staging DB from the fresh initializer with synthetic fixtures only.
- Verify prepared insert/list/cursor results and UTC timestamps on the selected PHP/MariaDB versions.
- Use a deliberately restricted/failing audit insert privilege to verify transaction rollback and
  generic JSON errors; restore the disposable fixture environment, never a production grant.
- Confirm DB account has no DELETE/UPDATE/DDL or unrelated database permissions.
- Ensure all disabled endpoints reject requests without side effects even with an admin token.
- Verify CORS preflight, token expiry/revocation and body limits over actual staging HTTPS.
- Only then consider approved Flutter rebuild/device tests. No destructive API should be enabled for testing
  until deletion semantics, snapshot/recovery and explicit test approval exist.
