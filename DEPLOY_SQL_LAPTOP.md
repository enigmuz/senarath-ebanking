# Senarath Bank Lab - Separate MSSQL laptop (SQL01) + Dockerized web tier

> **LAB ENVIRONMENT.** Two machines on the same isolated LAN:
>
> - **SQL01** - your dedicated laptop running **Microsoft SQL Server 2022**
>   (Windows). Internal only; never exposed to the internet. Holds the
>   `SenarathLab` database. This is the lab's internal MSSQL host (attack
>   stages 9-13 target it).
> - **WEB01** - the web-server laptop running **Ubuntu + Docker**: the
>   `corporate` site, the `ebanking` app and the `nginx gateway` (as in
>   `DEPLOY_UBUNTU.md`). Public via 80/443. **No MSSQL container here.**
>
> The ebanking container connects out to `SQL01:1433` with the SQL login
> `lab_app` / `DbLab123!` (weakness V6). This guide covers configuring SQL01
> and wiring WEB01 to it.

```
   Internet ──► WEB01 (Ubuntu/Docker)            SQL01 (Windows)
                 nginx ─ corporate                MSSQL 2022
                      └ ebanking ───TCP 1433────►  SenarathLab
                 public 80/443                     internal only
                        └──────────── same LAN ───────────┘
```

**Before you start**

- Put both laptops on the same isolated lab LAN/subnet.
- Give **SQL01 a static IP** (or a DHCP reservation) so it doesn't change -
  e.g. `192.168.10.20`. You'll point the app at this address. Throughout below,
  replace `SQL01_IP` with it, and `WEB01_IP` with the web laptop's address.

---

# PART A - Configure the SQL01 laptop (Windows)

You need: SQL Server 2022 (Developer or Express is fine for a lab) and SSMS.
If SQL Server isn't installed yet, install **SQL Server 2022 Developer** and
**SQL Server Management Studio (SSMS)** first, then continue.

## A1. Enable SQL (mixed-mode) authentication
The app signs in with a SQL login, so Windows-only auth won't work.

- SSMS → connect to the instance → right-click the **server** → **Properties**
  → **Security** → select **SQL Server and Windows Authentication mode** → OK.
- Restart the service: **SQL Server Configuration Manager** → *SQL Server
  Services* → right-click **SQL Server (MSSQLSERVER)** → **Restart**.

## A2. Enable TCP/IP and pin port 1433
- **SQL Server Configuration Manager** → *SQL Server Network Configuration* →
  **Protocols for MSSQLSERVER** → set **TCP/IP = Enabled**.
- Double-click **TCP/IP** → **IP Addresses** tab → scroll to **IPAll**:
  - **TCP Dynamic Ports** = *(blank - clear it)*
  - **TCP Port** = `1433`
- **Restart** the SQL Server service again.

> **If you installed SQL Server Express**, the instance is usually
> `SQL01\SQLEXPRESS` on a *dynamic* port. Pin it to **1433** exactly as above
> (clear dynamic ports, set 1433). The app connects by `host:1433`, so a static
> port avoids needing the SQL Browser service.

## A3. Open the Windows Firewall for 1433 (scoped to WEB01)
In an **elevated PowerShell** on SQL01:
```powershell
New-NetFirewallRule -DisplayName "MSSQL 1433 (lab, from WEB01)" -Direction Inbound `
  -Protocol TCP -LocalPort 1433 -Action Allow -RemoteAddress <WEB01_IP>
```
Scope it to `WEB01_IP` (or your lab subnet, e.g. `192.168.10.0/24`). **Do not**
open 1433 to the internet - SQL01 stays internal.

## A4. Create the database, tables, login and seed data
Copy the `senarath-ebanking/db/` folder onto SQL01 (USB, share, or `git`).
Open an elevated **Command Prompt** in that folder and run (replace the sa
password with yours):

```bat
sqlcmd -S localhost -U sa -P "<YOUR_SA_PASSWORD>" -i 01_schema.sql
sqlcmd -S localhost -U sa -P "<YOUR_SA_PASSWORD>" -d SenarathLab -i 02_seed_customers.sql
```
(Or open each file in SSMS and press Execute.)

Expected:
```
SenarathLab schema created. Next: run 02_seed_customers.sql
Seed complete: 205 customers, 406 accounts, 3445 transactions, 122 beneficiaries.
```

`01_schema.sql` creates the `SenarathLab` database, all tables, and the
**`lab_app`** login (`DbLab123!`) with `db_owner` on `SenarathLab`.

## A5. (Full attack chain only) grant lab_app sysadmin - weakness D1
If your event runs the internal stages (10: `xp_cmdshell` → 11: SYSTEM → AD),
the Infrastructure/AD team maps the V6 login to **sysadmin** so the chain can
enable and run `xp_cmdshell`. On the **isolated** SQL01 only:
```sql
ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
-- xp_cmdshell itself is enabled by the testers during stage 10; to pre-enable:
-- EXEC sp_configure 'show advanced options',1; RECONFIGURE;
-- EXEC sp_configure 'xp_cmdshell',1; RECONFIGURE;
```
> Skip A5 if you only need the web-tier app to work and to demo V1-V6. The app
> needs just `db_owner`.

## A6. Verify locally, then that the login works
```bat
sqlcmd -S localhost -U lab_app -P "DbLab123!" -d SenarathLab -Q "SELECT COUNT(*) AS customers FROM customers;"
REM -> customers = 205
```

---

# PART B - Point WEB01 (Docker) at SQL01

On the **web-server laptop** (Ubuntu). This assumes the web tier is already
installed per `DEPLOY_UBUNTU.md` **steps 1, 2, 6, 7** (Docker, repos cloned,
gateway) - the only difference is the database lives on SQL01, so there is **no
`sql01` container** and **no `docker network` MSSQL**. Just configure the
connection.

## B1. Set the database host to SQL01's IP
Edit `senarath-ebanking/.env`:
```bash
cd ~/senarath/senarath-ebanking
sed -i 's/^DB_HOST=.*/DB_HOST=<SQL01_IP>/' .env     # e.g. 192.168.10.20
grep -E 'DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASSWORD' .env
```
Should read:
```
DB_HOST=<SQL01_IP>
DB_PORT=1433
DB_NAME=SenarathLab
DB_USER=lab_app
DB_PASSWORD=DbLab123!
```

> **Using the name `SQL01.senarath.local` instead of an IP?** The container
> won't resolve that name on its own. Either keep `DB_HOST=SQL01.senarath.local`
> and set the real IP in `senarath-ebanking/docker-compose.yml`:
> ```yaml
>   ebanking:
>     extra_hosts:
>       - "SQL01.senarath.local:<SQL01_IP>"
> ```
> or simplest: just put the **IP** in `DB_HOST` and ignore `extra_hosts`.

A Docker container reaches a LAN host (SQL01) through the host's network
automatically - no special networking is needed beyond the IP being routable
from WEB01.

## B2. Bring up the web tier (no DB container)
```bash
cd ~/senarath/senarath-corporate && docker compose up -d --build
cd ~/senarath/senarath-ebanking  && docker compose up -d --build
docker ps        # senarath-corporate, senarath-ebanking, senarath-gateway
```

## B3. Verify the connection end to end
```bash
# From WEB01 host: is SQL01:1433 reachable?
nc -vz <SQL01_IP> 1433

# From inside the ebanking container (this is the real path the app uses):
docker exec -it senarath-ebanking bash -lc \
  '(echo > /dev/tcp/<SQL01_IP>/1433) && echo "1433 reachable" || echo "1433 blocked"'

# Then load the app and log in:
curl -s -o /dev/null -w "ebanking %{http_code}\n" -H 'Host: ebanking.senarathbank.com' http://127.0.0.1/index.php
```
Open `http://ebanking.senarathbank.com` and log in with
`customer01 / Welcome123!`. If you get the "briefly unavailable" page, the app
reached the web tier but not the database - see troubleshooting below.

---

# PART C - Connectivity troubleshooting (WEB01 → SQL01)

Work down this list; these are the usual causes, in order:

1. **TCP/IP not enabled / wrong port** on SQL01 → redo **A2**, restart the
   service. Confirm it's listening: on SQL01,
   `netstat -ano | findstr :1433` should show `LISTENING`.
2. **Firewall** blocking → redo **A3**; temporarily test with the firewall rule
   widened to the subnet.
3. **Mixed-mode auth off** (login fails even though port is open) → redo **A1**,
   restart.
4. **Wrong IP** (DHCP changed it) → give SQL01 a static IP / reservation and
   update `DB_HOST`.
5. **Named instance / Express on a dynamic port** → pin TCP 1433 (A2), or start
   the **SQL Server Browser** service and connect by instance name.
6. **App reached the DB check in A6 but the container can't** → confirm
   `DB_HOST` is the IP (or `extra_hosts` is set), then rebuild so the FreeTDS
   driver is in the image: `docker compose build --no-cache ebanking && docker compose up -d`.
7. Inspect logs: `docker logs --tail 50 senarath-ebanking`. The
   "briefly unavailable" page also prints the host/port it tried and the driver
   error.

Quick remote test of the actual SQL login from WEB01 (optional, needs the
`mssql-tools` image):
```bash
docker run --rm mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S <SQL01_IP>,1433 -U lab_app -P "DbLab123!" \
  -d SenarathLab -Q "SELECT COUNT(*) FROM customers;"
```

---

# PART D - Networking & safety summary

- **SQL01 is internal only.** Firewall 1433 to WEB01 (or the lab subnet) - never
  the internet. No public NAT/port-forward to SQL01.
- **WEB01 is the DMZ.** Expose only **80/443** publicly; the app makes the
  outbound connection to SQL01, so SQL01 needs no inbound internet access.
- **Static addressing** for SQL01 keeps `DB_HOST` valid across reboots.
- The app uses **SQL authentication** (`lab_app`), so WEB01 does **not** need to
  be domain-joined. SQL01 being part of `senarath.local` / the AD build is the
  Infrastructure team's concern (stages 12-17), independent of the app's DB
  connection.
- Keep lab passwords (`DbLab123!`, the sa password) off anything real; bring the
  environment up only for the demo window.

### Quick reference
```
SQL01 (Windows):   mixed-mode auth · TCP/IP + port 1433 · firewall 1433 from WEB01
                   run 01_schema.sql then 02_seed_customers.sql  (sa)
                   [full chain] ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
WEB01 (Ubuntu):    .env  DB_HOST=<SQL01_IP>   (no sql01 container)
                   docker compose up -d --build   (corporate, then ebanking)
verify:            docker exec senarath-ebanking bash -lc '(echo >/dev/tcp/<SQL01_IP>/1433) && echo ok'
```
