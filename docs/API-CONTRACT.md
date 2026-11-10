# Current client contract and staging changes

Observed from the two existing Flutter lib/main.dart and AppConstants.dart files, unchanged by this task.

## Original implementation
All clients use an HTTPS Hostinger base URL hardcoded into Dart. No login, token or authorization header exists.

| Endpoint under /qr/ | Actual caller/request | Expected old response |
| --- | --- | --- |
| save_scan.php | Scanner Save; POST application/json with qr_data string | status and message; client decodes JSON but does not robustly verify HTTP or status |
| get_random_scans.php | Admin initState and recurring five-second GET | status=success, count, data array of id/qr_data/scanned_at |
| delete_scan.php | Admin confirmation then POST form field id | status=success and message; removes matching displayed row |
| delete_all_scans.php | Admin confirmation then POST with no application payload | status=success and message; clears displayed rows |
| create_employee.php | Constant only, not called by current UI; JSON id/name | upsert result status/message |
| dashboard.php | Constant only, not called | total/present/absent/details |
| submit_scan.php | Constant only, not called; legacy PHP GET query id mutates attendance | status/message |

The scanner stores the last camera result in memory and sends it only after Save. It does NOT parse an
employee ID, call create_employee/submit_scan, or enforce once-per-day scans. Original save_scan inserts
opaque text into random_scans. Admin displays arbitrary scans, polls immediately, and exports IDs,
QR text and timestamps to public Downloads. Error handling mostly exposes exception text; HTTP status,
timeouts, cancellation and lifecycle guards are incomplete.

## Implemented staging contract
Every data request requires Authorization: Bearer <operator-issued-token> over HTTPS.
401 means missing/invalid/expired/revoked token; 403 means forbidden role/origin/host;
405 includes Allow; 413 body too large; 415 wrong media type; 422 invalid scan;
501 means an intentionally disabled operation; 503 means configuration/storage unavailable.
Error envelope: status=error, code, message, request_id (bootstrap/configuration errors may omit request_id).
Errors never include SQL, paths, tokens or DB credentials.

POST /qr/save_scan.php (scanner only):
- JSON object containing exactly one property, qr_data.
- Nonempty UTF-8 string, <=4096 bytes, no NUL; body <=8192 bytes.
- Preserves content verbatim, including whitespace and line breaks; performs no URL lookup or employee mapping.
- Returns HTTP 201, status=success, message, integer id and request_id.
- Literal string "0" is accepted; the old PHP truthiness rejection was not a meaningful QR-format policy.
- Duplicates remain allowed. No employee lookup, attendance change or deduplication was added.
- UTC is the staging storage baseline. Attendance/day semantics remain unresolved and disabled.

GET /qr/get_random_scans.php (administrator only):
- limit is an ASCII decimal integer 1..100; default 25.
- Optional before_id is 1..2147483647; keyset cursor, not offset.
- Unknown/array query parameters are rejected. Returns newest ID first.
- Keeps status/count/data; count now means the CURRENT PAGE, not every stored row.
- Adds pagination: limit, has_more, next_before_id; no unbounded total-count query.

GET /auth/whoami.php:
- Both roles; no query parameters and no DB connection.
- Returns status, identity {subject, role, expires_at}, request_id.
- Does not mint tokens or convert client-provided roles into authority.

POST delete_scan/delete_all_scans/create_employee and GET dashboard:
- Administrator authentication required, but ALL return 501 after authorization. No DB call.
POST submit_scan: scanner role, then 501. Legacy state-mutating GET is 405.
There is no flag that enables these features. Implementation requires a separately reviewed change.

Browser OPTIONS preflight is the only anonymous exception: exact approved Origin/method/headers,
204 metadata only, no data access or DB connection. Native apps omit Origin. CORS is not authentication.
Origin lists default empty, so browser use is disabled until explicitly configured.
