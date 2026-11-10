# Questions requiring approval; affected routes remain disabled

1. Is this product arbitrary QR collection or employee lunch attendance? Current clients use random_scans,
   while unused routes update employees and lunch_scans has no writer.
2. How does QR text map to an employee, if at all? Is the ID trusted/signed, what is its syntax,
   and should unknown employees be accepted? No mapping is implemented.
3. Define the business timezone, day boundary, repeat-scan policy, simultaneous scans and offline replay.
   Does attendance reset daily? Is historical lunch_scans the source of truth or employees.scanned_at?
4. Who can create/rename employees? Should create_employee upsert existing names or reject duplicates?
   Is there tenant/department scoping? No employee management is enabled.
5. Who may delete one scan; why; soft vs hard delete; audit retention; recovery requirements?
6. What exactly does delete all mean: current operator, tenant, time window, displayed page or entire DB?
   Does it affect attendance/history, and is stronger confirmation/re-authentication required?
   Both single and bulk deletion remain disabled, rather than silently choosing new semantics.
7. Choose the authorized staging identity provisioner, operator list, token distribution and revocation owner.
   Confirm whether production requires an external login/MFA provider; no accounts are fabricated.
8. Approve technical staging limits (8192-byte body, 4096-byte QR, 25/100-row pages),
   UTC storage and existing duplicate acceptance. These are explicit staging choices, not final business policy.
9. Define retention/privacy and whether QR payloads contain personal or credential-like material.
   Synthetic fixtures only are permitted in this preparation.

Code for any of these ambiguous functions must be reviewed separately before it can be enabled.
Do not remove the disabled guard merely to match the unauthenticated old UI.
