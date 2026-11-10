# Required future Flutter changes — none applied
The existing APKs still point at production and will fail the new authenticated contract.
Do not launch them to test this preparation. Do not repoint production DNS or intercept TLS.

After separate approval:
1. Add an explicit staging API base-URL build configuration and unmistakable staging app identity/label.
   The current code does not yet read a dart-define URL. Keep production defaults isolated and reviewed.
2. Add a token provisioning/sign-in screen. Obtain a short-lived per-operator token securely,
   validate it through GET /auth/whoami.php, and use only the returned verified role/expiry.
   No password or token may be hardcoded. Select a long-term login provider separately.
3. Store the token with OS-keystore-backed secure storage (new dependency/platform work needs approval).
   Keep it out of logs, preferences, analytics, exports and screenshots. Clear it on sign-out/expiry.
4. Centralize API requests with Authorization bearer headers, JSON content type, bounded timeouts,
   cancellation, safe status/code parsing and no TLS/certificate bypass.
5. Do not start polling or camera-submission calls until authentication and staging-host checks pass.
   On 401 stop polling, clear token and show sign-in; on 403 show permission denial; on 501 show disabled.
   Do not retry destructive actions or send a scan to a different/fallback production endpoint.
6. Scanner: accept HTTP 201 + status=success, handle 4xx/503 clearly, preserve QR data verbatim.
   No automatic employee lookup, QR interpretation or duplicate policy should be added yet.
7. Admin: implement keyset pagination/load-more using next_before_id and page count.
   Keep polling bounded, cancel delayed work on dispose/sign-out, use mounted guards/backoff.
   Avoid fetching every page every five seconds. Display errors without raw server exceptions.
8. Keep deletion and employee/attendance controls disabled. UI hiding is additional to server enforcement;
   no operator can enable the server routes by changing a role field or client flag.
9. Revisit broad storage access and fixture-only export behavior separately; no permission changes here.
10. Review source diffs, build new staging APKs, inspect the embedded host, and block production egress
    BEFORE any approved device installation or launch. Current APKs remain untouched.
