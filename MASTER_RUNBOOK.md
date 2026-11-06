# Senarath Bank Lab - Master Setup Runbook (built exactly as the build guide)

> **LAB ENVIRONMENT.** Fictional bank, synthetic data, intentional weaknesses.
> This runbook follows the *Senarath Bank Lab Build Guide* network design
> (pages 9-11, 15). Build **SQL01 first**, then **WEB01**.

---

## 1. Zones and machines  (build guide pages 9-10)

The lab has two zones that are **directly connected** - there is **no internal
firewall** between them (page 11). The WAF sits only on the public web route.

```
            INTERNET
               │  ebanking.senarathbank.com  (public HTTPS)
               ▼
   ┌───────────────── DMZ 1 ─────────────────┐
   │  WAF01  (WAF / CDN, public entry)        │
   │     │                                    │
   │     ▼                                    │
   │  WEB01  (origin: corporate + ebanking)   │  also directly reachable on its
   │     │   Ubuntu + Docker                  │  origin IP  → that is V2
   └─────┼────────────────────────────────────┘
         │  TCP 1433  (direct internal communication, no firewall)
         ▼
   ┌───────── INTERNAL NETWORK / senarath.local ─────────┐
   │  SQL01   MSSQL 2022   (SenarathLab)                  │
   │  HR01    legacy HR server     DC01   domain controller│  (infra team, D-stages)
   └──────────────────────────────────────────────────────┘
```

| Tag | Machine | Role | Zone |
|-----|---------|------|------|
| **[WAF01]** | WAF / CDN laptop | Public HTTPS entry, upload filtering | DMZ 1 |
| **[WEB01]** | Ubuntu + Docker laptop | corporate + ebanking + nginx gateway (origin) | DMZ 1 |
| **[SQL01]** | Windows laptop | Microsoft SQL Server 2022, `SenarathLab` | Internal / senarath.local |
| [HR01] / [DC01] | *(infra/AD team)* | legacy HR + domain controller (stages 12-17) | Internal / senarath.local |

**Connectivity to provision** (build guide page 11 - "Required lab connectivity"):

| Path | Source | Destination | Port |
|------|--------|-------------|------|
| **Web to SQL** | WEB01 (DMZ) | SQL01 (internal) | **TCP 1433** |
| SQL to HR | SQL01 | HR01 | SMB 445 / RDP 3389 / WinRM 5985 |
| HR to DC | HR01 | DC01 | SMB 445 / Kerberos 88 / LDAP 389 |

This runbook covers the **web + database** connection (Web→SQL 1433). HR01/DC01
are the Infrastructure/AD team's build for stages 12-17.

---

## 2. Fixed values and where to find them  (build guide pages 13, 15)

| Value | What it is | Where it comes from |
|-------|-----------|---------------------|
| `DB_HOST=SQL01.senarath.local` | MSSQL host name (used by the app) | Already set in **`senarath-ebanking/.env`** (page 15). Keep as is. |
| `DB_PORT=1433` | MSSQL port | `senarath-ebanking/.env` |
| `DB_NAME=SenarathLab` | Database name | `senarath-ebanking/.env`; created by `db/01_schema.sql` |
| `DB_USER=lab_app` / `DB_PASSWORD=DbLab123!` | App DB login (**V6**) | `senarath-ebanking/.env`; login created by `db/01_schema.sql` |
| `SQL01_IP` | SQL01's actual address on the lab network | **On SQL01:** `ipconfig` → IPv4. Record it at provisioning (page 15). You map the name `SQL01.senarath.local` to it. |
| `WEB01_IP` | WEB01's address / discoverable origin IP | **On WEB01:** `hostname -I`. Its origin IP is intentionally discoverable (V2). |
| `SA_PASSWORD` | MSSQL `sa` admin password | **You choose it** at SQL Server install (Mixed Mode screen). Not in any file. |
| domain | `senarath.local` (NetBIOS `SENARATH`) | build guide page 13 |

> The app connects **by the name `SQL01.senarath.local`**, exactly as the guide
> specifies. You make that name resolve to `SQL01_IP` via the Docker
> `extra_hosts` mapping in step C3 - no `.env` change needed.

---

## 3. Order
```
A  Network           [both]   two zones, direct connectivity, record IPs
B  Database          [SQL01]  install → auth → TCP 1433 → allow 1433 → schema+seed → verify
C  Web tier          [WEB01]  Docker → code → map SQL01.senarath.local → compose up
D  Connect & verify  [WEB01]  name resolves, 1433 reachable, login works
E  Publish           [WAF/edge] WAF public route + origin IP reachable (V2)
F  Ops / safety
```

---

# STAGE A - Network  **[both]**

1. Put WEB01 (DMZ) and SQL01 (internal) on the lab network with **direct
   connectivity between them** (the lab design has **no internal firewall**).
2. **[SQL01]** `ipconfig` → record the IPv4 as `SQL01_IP` (make it static).
3. **[WEB01]** `hostname -I` → record `WEB01_IP`.
4. **[WEB01]** confirm the path exists: `ping <SQL01_IP>`.

---

# STAGE B - Database  **[SQL01 - Windows]**   *(do this first)*

Install **SQL Server 2022** + **SSMS** if not present.

### B1  SQL (mixed-mode) authentication
SSMS → server **Properties** → **Security** → **SQL Server and Windows
Authentication mode** → OK. Restart: Configuration Manager → *SQL Server
Services* → **SQL Server (MSSQLSERVER)** → **Restart**.

### B2  TCP/IP + port 1433
Configuration Manager → *SQL Server Network Configuration* → **Protocols for
MSSQLSERVER** → **TCP/IP = Enabled**. Double-click **TCP/IP** → **IP Addresses**
→ **IPAll**: **TCP Dynamic Ports** = *(blank)*, **TCP Port** = `1433`. **Restart**
the service. Confirm: `netstat -ano | findstr :1433` → `LISTENING`.

### B3  Allow 1433 (direct connectivity, per the lab design)
The lab has no internal firewall, so the Windows Firewall must permit 1433 on the
lab network. Elevated **PowerShell**:
```powershell
New-NetFirewallRule -DisplayName "MSSQL 1433 (lab)" -Direction Inbound `
  -Protocol TCP -LocalPort 1433 -Action Allow
```
(If you want to scope it, add `-RemoteAddress <lab-subnet>` e.g. `192.168.10.0/24`.)

### B4  Create the database + load data
Copy **`senarath-ebanking/db/`** onto SQL01. Elevated **Command Prompt** in it:
```bat
sqlcmd -S localhost -U sa -P "<SA_PASSWORD>" -i 01_schema.sql
sqlcmd -S localhost -U sa -P "<SA_PASSWORD>" -d SenarathLab -i 02_seed_customers.sql
```
Expected: `Seed complete: 205 customers, 406 accounts, 3445 transactions, 122 beneficiaries.`
This creates `SenarathLab` and the `lab_app` / `DbLab123!` login (V6).

### B5  (Full attack chain) grant lab_app sysadmin - weakness D1 (page 13)
Only if running the internal stages (xp_cmdshell → SYSTEM → AD). In SSMS:
```sql
ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
```
Skip for app-only / V1-V6.

### B6  Verify
```bat
sqlcmd -S localhost -U lab_app -P "DbLab123!" -d SenarathLab -Q "SELECT COUNT(*) AS customers FROM customers;"
REM -> 205
```

---

# STAGE C - Web tier  **[WEB01 - Ubuntu]**

### C1  Install Docker
```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
 | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER && newgrp docker
```

### C2  Get the code
```bash
mkdir -p ~/senarath && cd ~/senarath
git clone <your-corporate-repo-url> senarath-corporate
git clone <your-ebanking-repo-url>  senarath-ebanking
```

### C3  Make `SQL01.senarath.local` resolve to SQL01  (the connection)
`.env` already has `DB_HOST=SQL01.senarath.local` (as the guide specifies - leave
it). Point that name at SQL01's address in **`senarath-ebanking/docker-compose.yml`**:
```yaml
  ebanking:
    extra_hosts:
      - "SQL01.senarath.local:<SQL01_IP>"     # <- set to SQL01's recorded IP
```
```bash
cd ~/senarath/senarath-ebanking
sed -i 's/SQL01.senarath.local:.*/SQL01.senarath.local:<SQL01_IP>"/' docker-compose.yml
grep extra_hosts -A1 docker-compose.yml
```

### C4  Start corporate, ebanking and the gateway
```bash
cd ~/senarath/senarath-corporate && docker compose up -d --build
cd ~/senarath/senarath-ebanking  && docker compose up -d --build
docker ps
```

---

# STAGE D - Connect & verify  **[WEB01]**

```bash
# the container resolves the name and reaches 1433 (the app's real path)
docker exec -it senarath-ebanking bash -lc \
  'getent hosts SQL01.senarath.local; (echo > /dev/tcp/SQL01.senarath.local/1433) \
   && echo "SQL01:1433 reachable" || echo "blocked"'

# the SQL login works from WEB01 (optional)
docker run --rm --add-host SQL01.senarath.local:<SQL01_IP> mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S SQL01.senarath.local,1433 -U lab_app -P "DbLab123!" \
  -d SenarathLab -Q "SELECT COUNT(*) FROM customers;"

# the app answers
curl -s -o /dev/null -w "ebanking %{http_code}\n" -H 'Host: ebanking.senarathbank.com' http://127.0.0.1/index.php
```
Then log in at the app with **customer01 / Welcome123!**. "Briefly unavailable"
→ see Troubleshooting.

---

# STAGE E - Publish the web route  (build guide pages 9, 18)

The **public** path is `ebanking.senarathbank.com` → **WAF/CDN (WAF01)** → **WEB01
origin**. The origin is **also directly reachable on its IP** - that direct route
is **weakness V2** (the WAF is out of the path), so it is intentional.

- Point `ebanking.senarathbank.com` / `www.senarathbank.com` at **WAF01** (the WAF
  forwards to WEB01 as its origin). Enable upload filtering **only at the WAF**,
  never at the origin (V2, page 18).
- Make the **origin IP discoverable** (a leaked/historical A record, a dev
  subdomain, or an MX/SPF record) and have WEB01 accept HTTPS/80 for
  `Host: ebanking.senarathbank.com` from any lab source. That is the direct origin
  route used for V2/V5.
- TLS: terminate at WAF01 and/or on WEB01's gateway (certbot + the commented 443
  blocks in `senarath-ebanking/nginx/gateway.conf`).

> SQL01 is **not** on the public route - it stays in the internal zone. Only the
> web tier is published. The whole lab remains isolated from production (page 10).

---

# STAGE F - Ops & safety

```bash
docker ps
docker logs --tail 50 senarath-ebanking
# re-seed [SQL01]: sqlcmd -U sa -P "<SA_PASSWORD>" -d SenarathLab -i 02_seed_customers.sql
# clear V5 uploads [WEB01]: docker exec senarath-ebanking sh -lc 'rm -f /var/www/html/uploads/*.php'
# stop: docker compose down  (in each repo)
```
- Keep the whole lab **isolated from production** (page 10).
- Never reuse the lab passwords anywhere real.
- Bring it up for the demo window; tear down after.

---

# Troubleshooting (WEB01 → SQL01)
1. Name doesn't resolve in the container → fix `extra_hosts` IP (C3), `docker compose up -d`.
2. 1433 blocked → B2 (TCP/IP+1433, restart) and B3 (allow 1433); `netstat ...:1433` must be LISTENING.
3. Login fails though port open → B1 mixed-mode auth, restart.
4. Wrong/changed `SQL01_IP` → static IP, update `extra_hosts`.
5. SQL Express dynamic port → pin 1433 (B2).
6. Still failing → `docker compose build --no-cache ebanking && docker compose up -d`; read `docker logs senarath-ebanking`.

### One screen
```
[SQL01]  mixed auth → TCP/IP+1433 → allow 1433 (no internal firewall)
         sqlcmd -U sa -i 01_schema.sql ; -d SenarathLab -i 02_seed_customers.sql
         [full chain] ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
[WEB01]  Docker → clone → set extra_hosts "SQL01.senarath.local:<SQL01_IP>"
         (keep DB_HOST=SQL01.senarath.local)  → docker compose up -d --build
         verify: docker exec senarath-ebanking bash -lc '(echo >/dev/tcp/SQL01.senarath.local/1433)&&echo ok'
[edge]   ebanking.senarathbank.com → WAF01 → WEB01 origin ; origin IP discoverable (V2)
[login]  customer01 / Welcome123!
```
