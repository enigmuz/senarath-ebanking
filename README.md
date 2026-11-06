# Senarath Bank — Online Banking (`senarath-ebanking`)

> **LAB ENVIRONMENT.** Senarath Bank is a **fictional** institution and all data is
> **synthetic**. This application is **deliberately vulnerable** — it implements the
> six documented web weaknesses (**V1–V6**) from the *Senarath Bank Lab Build Guide*
> for the CrowdStrike security demonstration (Part A, web foothold, stages 1–8).
> **Do not deploy outside an isolated, time-boxed lab.** See
> [`DEPLOYMENT_GUIDE.md`](DEPLOYMENT_GUIDE.md) §12.

A realistic online-banking app — login → OTP → dashboard → accounts → transactions →
transfers → beneficiaries → statements → document upload — built on PHP + Apache and
backed by **Microsoft SQL Server** on the internal host **SQL01**. It ships with an
**nginx application gateway** that also fronts the corporate site.

## Intentional weaknesses (full detail in [`VULNERABILITIES.md`](VULNERABILITIES.md))

| ID | Weakness | Where |
|----|----------|-------|
| **V1** | Leaked (synthetic) customer credential exists | DB seed + external OSINT seeding |
| **V2** | WAF bypass via direct origin IP | `nginx/gateway.conf` + origin exposure |
| **V3** | Valid leaked credential logs in | `src/api/login.php` |
| **V4** | Broken 2FA — pre-OTP token disclosure | `src/api/login.php`, `src/helpers.php`, `src/includes/auth.php` |
| **V5** | Unrestricted file upload → RCE | `src/upload.php` + `Dockerfile` |
| **V6** | DB credentials in plaintext env | `.env`, `src/config.php` |

The chain hands off to the Infrastructure/AD team (Part B, D1–D6) with a WEB01
foothold (V5) and working MSSQL credentials (V6) for `SQL01:1433`.

## Stack

- **PHP 8.2 + Apache** (`php:8.2-apache`), MSSQL via **FreeTDS / PDO dblib**
- **MSSQL 2019/2022** on SQL01 (`SenarathLab` database)
- **nginx** application gateway (routes by hostname)
- **Docker Compose** on Ubuntu (WEB01)

## Layout

```
senarath-ebanking/
├─ Dockerfile                 # PHP+Apache image, FreeTDS driver, uploads kept executable (V5)
├─ docker-compose.yml         # ebanking app + nginx gateway, joins senarath-net
├─ .env                       # PLAINTEXT lab DB creds — committed on purpose (V6)
├─ .env.example
├─ nginx/gateway.conf         # host-based routing; no upload filtering at origin (V2)
├─ db/
│  ├─ 01_schema.sql           # SenarathLab DB, tables, lab_app login
│  └─ 02_seed_customers.sql   # 205 synthetic customers, accounts, ~3,400 txns, payees
├─ src/
│  ├─ config.php              # reads DB creds from env (V6)
│  ├─ db.php                  # PDO/dblib connection to SQL01:1433
│  ├─ helpers.php             # sessions; mints authenticated session pre-OTP (V4)
│  ├─ index.php               # login page
│  ├─ otp.php                 # OTP step (present, not enforced — V4)
│  ├─ api/{login,verify-otp,logout}.php
│  ├─ dashboard.php accounts.php transactions.php transfer.php
│  ├─ beneficiaries.php statements.php profile.php
│  ├─ upload.php              # unrestricted upload handler (V5)
│  ├─ includes/{auth,layout}.php
│  └─ assets/{css,js,img}/    # app.css, logo.png
├─ DEPLOYMENT_GUIDE.md        # full Ubuntu + Docker + nginx + MSSQL walkthrough
├─ VULNERABILITIES.md         # V1–V6 mapping, verification, acceptance tests
└─ README.md
```

## Quick start (lab)

Full instructions — including SQL01 setup, the two-repo Git workflow, DNS, TLS and
safety — are in **[`DEPLOYMENT_GUIDE.md`](DEPLOYMENT_GUIDE.md)**. The short version,
once SQL01 is seeded and `.env` points at it:

```bash
# On WEB01 (Ubuntu + Docker):
docker network create senarath-net                       # once
cd senarath-corporate && docker compose up -d --build    # corporate site
cd ../senarath-ebanking && docker compose up -d --build  # banking app + gateway

# Browser: http://ebanking.senarathbank.com  (log in: customer01 / Welcome123!)
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
