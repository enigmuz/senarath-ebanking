# Senarath Bank — Online Banking (`senarath-ebanking`)

> **LAB ENVIRONMENT.** Senarath Bank is a **fictional** institution and all data is
> **synthetic**. This application is **deliberately vulnerable** — it implements the
> six documented web weaknesses (**V1–V6**) from the *Senarath Bank Lab Build Guide*
> for the CrowdStrike security demonstration (Part A, web foothold). **Do not deploy
> outside an isolated, time-boxed lab.** See [`DEPLOY.md`](DEPLOY.md).

A realistic online-banking app — login → OTP → dashboard → accounts → transactions →
transfers → beneficiaries → statements → document upload — built on **PHP 8.2 +
Apache** and backed by **Microsoft SQL Server** on the internal host **SQL01**.
It runs **natively** on Ubuntu (no Docker); the corporate site runs beside it from
the `senarath-corporate` repo.

## Intentional weaknesses (full detail in [`VULNERABILITIES.md`](VULNERABILITIES.md))

| ID | Weakness | Where |
|----|----------|-------|
| **V1** | Leaked (synthetic) customer credential exists | DB seed + external OSINT seeding |
| **V2** | WAF bypass via direct origin IP | `deploy/apache/senarath.conf` + origin exposure |
| **V3** | Valid leaked credential logs in | `src/api/login.php` |
| **V4** | Broken 2FA — pre-OTP token disclosure | `src/api/login.php`, `src/helpers.php`, `src/includes/auth.php` |
| **V5** | Unrestricted file upload → RCE | `src/upload.php` + Apache/mod_php (uploads executable) |
| **V6** | DB credentials in plaintext env | `.env`, `src/config.php` |

The chain hands off to the Infrastructure/AD team (Part B, D1–D6) with a WEB01
foothold (V5) and working MSSQL credentials (V6) for `SQL01:1433`.

## Stack

- **PHP 8.2 + Apache** (native, mod_php), MSSQL via **FreeTDS / PDO dblib** (`php8.2-sybase`)
- **MSSQL 2019/2022** on SQL01 (`SenarathLab` database)
- **Apache name-based virtual hosts** (route by hostname) — no containers

## Layout

```
senarath-ebanking/
├─ .env                       # PLAINTEXT lab DB creds — committed on purpose (V6)
├─ .env.example
├─ db/
│  ├─ 01_schema.sql           # SenarathLab DB, tables, lab_app login
│  └─ 02_seed_customers.sql   # 205 synthetic customers, accounts, ~3,400 txns, payees
├─ deploy/
│  ├─ apache/senarath.conf    # name-based vhosts (corporate + ebanking); no origin filtering (V2)
│  ├─ php/zz-ebanking.ini      # upload limits + display_errors
│  └─ install-native.sh        # one-command WEB01 installer (Apache + PHP 8.2 + FreeTDS)
├─ src/
│  ├─ config.php              # reads DB creds from .env/env (V6)
│  ├─ db.php                  # PDO/dblib connection to SQL01:1433
│  ├─ helpers.php             # sessions; mints authenticated session pre-OTP (V4)
│  ├─ index.php               # login page
│  ├─ otp.php                 # OTP step (present, not enforced — V4)
│  ├─ api/{login,verify-otp,logout}.php
│  ├─ dashboard.php accounts.php transactions.php transfer.php
│  ├─ beneficiaries.php statements.php profile.php
│  ├─ upload.php              # unrestricted upload handler (V5)
│  ├─ includes/{auth,layout}.php
│  └─ assets/{css,js,img}/ · uploads/
├─ DEPLOY.md                  # THE from-scratch Ubuntu deploy guide (native, no Docker)
├─ VULNERABILITIES.md         # V1–V6 mapping, verification, acceptance tests
├─ SQL01_PREPARE.md           # SQL01 + AD prep for the full chain (Part B)
├─ FULLCHAIN_BUILD_PLAN.md    # D1–D6 provisioning plan (Part B)
└─ README.md
```

## Quick start (lab)

Full, from-scratch instructions — pushing the repos, SQL01, DNS, native install,
TLS and the push/pull update loop — are in **[`DEPLOY.md`](DEPLOY.md)**. The short
version, on a plain Ubuntu WEB01:

```bash
sudo git clone https://github.com/enigmuz/senarath-corporate.git /var/www/senarath-corporate
sudo git clone https://github.com/enigmuz/senarath-ebanking.git  /var/www/senarath-ebanking
sudo SQL01_IP=<SQL01_IP> bash /var/www/senarath-ebanking/deploy/install-native.sh
sudo certbot --apache -d senarathbank.com -d www.senarathbank.com \
  -d ebanking.senarathbank.com -d www.ebanking.senarathbank.com --redirect
# Browser: https://ebanking.senarathbank.com  (log in: customer01 / Welcome123!)
```

## Default lab credentials (synthetic — never reuse)

| Purpose | Value |
|---|---|
| Customer login (V1/V3) | `customer01` / `Welcome123!` · `customer02` / `Summer123!` |
| Other seeded customers | `customer03`–`customer205` / `Senarath@123` |
| Demo OTP (not enforced) | `114477` (also logged per-login) |
| MSSQL app login (V6) | `lab_app` / `DbLab123!` → `SenarathLab` on `SQL01:1433` |

## Database configuration

| Env var | Default | Purpose |
|---|---|---|
| `DB_HOST` | `SQL01.senarath.local` | MSSQL host (set to SQL01's IP in `.env`) |
| `DB_PORT` | `1433` | MSSQL port |
| `DB_NAME` | `SenarathLab` | Database |
| `DB_USER` / `DB_PASSWORD` | `lab_app` / `DbLab123!` | App login (V6) |
| `LAB_FIXED_OTP` | `114477` | Demo OTP value |
| `APP_DEBUG` | `1` | Verbose errors (lab) |
