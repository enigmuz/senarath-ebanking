# SQL01 - Prepare the MSSQL server (full-chain build)

> **LAB ENVIRONMENT - isolated synthetic lab.** Everything you do **on the SQL01
> laptop** to serve `SenarathLab` to the e-banking app **and** be ready for the
> full 17-stage chain (D1/D2 on this host). Built from the *Senarath Bank Lab
> Build Guide* (pages 9-15, 26-28). Repos:
> - `https://github.com/enigmuz/senarath-ebanking` (SQL01 needs this for `db/`)
> - `https://github.com/enigmuz/senarath-corporate` (WEB01 only)
>
> **Scope:** this provisions the SQL **target**. Attack execution (xp_cmdshell
> abuse, SeImpersonate elevation) is the tester's own tooling during the event,
> per the guide - not in here. Never reuse these synthetic passwords anywhere real.

---

## 0. What this host becomes, and the dependency

SQL01 (full chain) = **Windows + SQL Server 2022, joined to `senarath.local`,
with SQL Server running as the domain account `SENARATH\svc_mssql`**. That single
posture gives D1 (privileged service account + xp_cmdshell) and D2 (SeImpersonate).

**Order dependency:** the domain parts need **DC01 built first** (the forest
`senarath.local` and the `svc_mssql` account must exist). See
`FULLCHAIN_BUILD_PLAN.md` §4.

**You can build in two passes** (recommended while you develop the chain):
- **Pass 1 - now (no DC yet):** PART A, C, E, F, G1 → the app works, DB is seeded,
  `lab_app` is sysadmin. Web weaknesses V1-V6 fully demoable.
- **Pass 2 - after DC01 is up:** PART B, D, G2 → domain join, SQL runs as
  `svc_mssql`, SPN, SeImpersonate posture → D1/D2 ready.

Fixed values (guide p.13-15):
| Item | Value |
|------|-------|
| Domain / NetBIOS | `senarath.local` / `SENARATH` |
| SQL service account | `SENARATH\svc_mssql` / `SqlSvc123!` |
| App SQL login (V6) | `lab_app` / `DbLab123!` |
| Database | `SenarathLab` |
| App connection | `DB_HOST=SQL01.senarath.local`, `DB_PORT=1433` |

---

# PART A - Install SQL Server + tools  *(Pass 1)*

### A1. Install on SQL01
1. **SQL Server 2022 - Developer edition** (free, full features).
2. **SQL Server Management Studio (SSMS)**.
3. **SQL Server Command Line Utilities** (`sqlcmd`) - usually comes with SSMS.
4. **Git for Windows** (or download the repo ZIP in PART E).

### A2. Install SQL Server with Mixed-Mode auth
Run the installer → Database Engine → at **Database Engine Configuration**:
- **Authentication Mode = Mixed Mode**.
- Set the **sa** password = your `SA_PASSWORD` (write it down).
- Add your Windows admin.
Install **SSMS** afterwards.

> Already installed Windows-only? SSMS → server **Properties** → **Security** →
> **SQL Server and Windows Authentication mode** → OK, then restart the service.

### A3. Name the machine `SQL01`
Settings → System → About → **Rename this PC** → `SQL01` → reboot. (It becomes
`SQL01.senarath.local` after the domain join in PART B.)

---

# PART B - Join SQL01 to the domain  *(Pass 2 - needs DC01)*

Point DNS at DC01, then join. Elevated **PowerShell**:
```powershell
# DC01 must be the DNS server (replace with DC01's IP)
Set-DnsClientServerAddress -InterfaceAlias "Ethernet" -ServerAddresses <DC01_IP>

# join senarath.local (prompts for a domain admin, e.g. SENARATH\lab_da)
Add-Computer -DomainName senarath.local -Credential senarath\lab_da -Restart
```
After reboot, SQL01 is `SQL01.senarath.local`.

---

# PART C - Network: TCP/IP, port 1433, services, firewall  *(Pass 1)*

### C1. Enable TCP/IP + pin 1433
**SQL Server Configuration Manager** → *SQL Server Network Configuration* →
**Protocols for MSSQLSERVER** → **TCP/IP = Enabled**. Double-click **TCP/IP** →
**IP Addresses** → **IPAll**: **TCP Dynamic Ports** = *(blank)*, **TCP Port** =
`1433`. Restart the SQL Server service.

Confirm (elevated cmd): `netstat -ano | findstr :1433` → `LISTENING`.

> **SQL Express / named instance:** pin TCP 1433 exactly as above so the app
> connects by `host:1433` without the SQL Browser.

### C2. Services
Configuration Manager → *SQL Server Services*:
| Service | Set to |
|---------|--------|
| **SQL Server (MSSQLSERVER)** | **Automatic**, **Running** |
| **SQL Server Browser** | **Automatic** (needed for named instances) |

### C3. Firewall - allow 1433 (lab has no internal firewall, guide p.11)
Elevated **PowerShell**:
```powershell
New-NetFirewallRule -DisplayName "MSSQL 1433 (lab)" -Direction Inbound `
  -Protocol TCP -LocalPort 1433 -Action Allow
```
(Scope with `-RemoteAddress <lab-subnet>` e.g. `192.168.10.0/24` if you prefer.)

---

# PART D - Run SQL Server as SENARATH\svc_mssql + SPN  *(Pass 2 - D1)*

This service context is what carries **SeImpersonate** (D2) and matches the
guide's D1 build (p.26). `svc_mssql` must already exist in AD (created on DC01).

### D1. Set the service logon account  (use Configuration Manager, not services.msc)
**SQL Server Configuration Manager** → *SQL Server Services* → right-click
**SQL Server (MSSQLSERVER)** → **Properties** → **Log On** tab → **This account**:
- Account: `SENARATH\svc_mssql`
- Password: `SqlSvc123!`
→ OK → **Restart** the service.
(Configuration Manager grants the account the required "Log on as a service" and
file/registry rights automatically.)

### D2. Register the service SPN  (so Kerberos works; D3 pattern)
Elevated **PowerShell/cmd** as a domain admin:
```bat
setspn -A MSSQLSvc/SQL01.senarath.local:1433 SENARATH\svc_mssql
setspn -L SENARATH\svc_mssql
```

---

# PART E - Get the database scripts onto SQL01  *(Pass 1)*

SQL01 only needs the **`db/`** folder from **senarath-ebanking**.

**Option 1 - Git:**
```bat
cd %USERPROFILE%\Desktop
git clone https://github.com/enigmuz/senarath-ebanking.git
cd senarath-ebanking\db
dir   REM 01_schema.sql, 02_seed_customers.sql
```
Private repo? `git clone https://<user>:<token>@github.com/enigmuz/senarath-ebanking.git`

**Option 2 - ZIP:** open `https://github.com/enigmuz/senarath-ebanking` → **Code**
→ **Download ZIP** → extract → scripts are in `senarath-ebanking\db\`.

**Option 3 - USB/share:** copy `senarath-ebanking/db/` from WEB01.

---

# PART F - Create the database, login and data  *(Pass 1)*

Elevated **Command Prompt** in the `db` folder:
```bat
sqlcmd -S localhost -U sa -P "<SA_PASSWORD>" -i 01_schema.sql
sqlcmd -S localhost -U sa -P "<SA_PASSWORD>" -d SenarathLab -i 02_seed_customers.sql
```
Expected: `Seed complete: 205 customers, 406 accounts, 3445 transactions, 122 beneficiaries.`

Creates `SenarathLab` + the `lab_app` / `DbLab123!` login (V6) with `db_owner`.

Verify locally:
```bat
sqlcmd -S localhost -U lab_app -P "DbLab123!" -d SenarathLab -Q "SELECT COUNT(*) AS customers FROM customers;"
REM -> 205
```

---

# PART G - Full-chain posture  (D1, D2)

### G1. Grant the app login sysadmin  *(Pass 1 - D1, guide p.26)*
The handed-off V6 login maps to a sysadmin. In SSMS on SQL01:
```sql
ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
```

### G2. xp_cmdshell + SeImpersonate posture  *(Pass 2 - D1/D2, guide p.26-28)*
- **xp_cmdshell** is the configured weakness. The guide builds the instance so it
  can be enabled; testers enable it during stage 10. To pre-enable it per the
  documented build (p.27), run in SSMS:
  ```sql
  EXEC sp_configure 'show advanced options', 1; RECONFIGURE;
  EXEC sp_configure 'xp_cmdshell', 1; RECONFIGURE;
  ```
  (Leave this to your run plan if you'd rather testers enable it live.)
- **SeImpersonate (D2):** because SQL runs as the domain **service account**
  (PART D), its token already carries `SeImpersonatePrivilege` - no extra hole.
  Confirm in that service context: `whoami /priv` shows
  **SeImpersonatePrivilege = Enabled**.
- Leave host-based prevention **off / log-only** so token abuse is recorded, not
  blocked. *The elevation tooling is the tester's.*

---

# PART H - Connections this host must have  (reference)

| Path | To | Ports | For |
|------|----|-------|-----|
| **WEB01 → SQL01** | this host | TCP **1433** | the app's DB path (V6/stage 9) |
| **SQL01 → HR01** | legacy HR | SMB **445** / RDP **3389** / WinRM **5985** | lateral move (stage 14) |

WEB01 keeps `DB_HOST=SQL01.senarath.local` and maps it to this host's IP in
`/etc/hosts` (the native installer does this from `SQL01_IP`):
```
<SQL01_IP>   SQL01.senarath.local
```
(Full WEB01 steps: `DEPLOY.md`. HR01/DC01 build: `FULLCHAIN_BUILD_PLAN.md`.)

**Test the app's path from WEB01:**
```bash
nc -vz SQL01.senarath.local 1433   # or: (echo > /dev/tcp/SQL01.senarath.local/1433) && echo reachable
```

---

# PART I - Verify, operate, troubleshoot

### Readiness checks on SQL01
```bat
netstat -ano | findstr :1433                 REM engine listening
sqlcmd -S localhost -U lab_app -P "DbLab123!" -d SenarathLab -Q "SELECT COUNT(*) FROM customers;"  REM 205
whoami /priv                                  REM in the SQL service context: SeImpersonatePrivilege = Enabled
setspn -L SENARATH\svc_mssql                  REM MSSQLSvc/SQL01.senarath.local:1433 present
```

### Re-seed between runs
```bat
sqlcmd -S localhost -U sa -P "<SA_PASSWORD>" -d SenarathLab -i 02_seed_customers.sql
```

### Troubleshooting (in order)
1. `sqlcmd` not found → install "Microsoft Command Line Utilities for SQL Server", or use SSMS.
2. 1433 not LISTENING → PART C1 (TCP/IP + 1433), restart.
3. Firewall → PART C3.
4. Login fails though port open → Mixed Mode auth (A2), restart.
5. Service won't start as `svc_mssql` → set it via **Configuration Manager** (not services.msc) so rights are granted; check the password; confirm the account isn't locked/expired.
6. SPN duplicate error → `setspn -Q MSSQLSvc/SQL01.senarath.local:1433` to find/clear duplicates.
7. Container can't resolve the name → fix `extra_hosts` IP on WEB01.
8. Express dynamic port → pin 1433 (C1).

### One-screen summary
```
PASS 1 (now):   install SQL2022 Mixed Mode (set sa) + SSMS/sqlcmd
                Config Mgr: TCP/IP=On, IPAll TCP Port=1433, restart ; services Automatic ; firewall allow 1433
                git clone https://github.com/enigmuz/senarath-ebanking (or ZIP) -> db\
                sqlcmd -U sa -i 01_schema.sql ; -d SenarathLab -i 02_seed_customers.sql
                ALTER SERVER ROLE sysadmin ADD MEMBER lab_app      (D1)
PASS 2 (DC up): Set-DnsClientServerAddress <DC01_IP> ; Add-Computer -DomainName senarath.local -Restart
                Config Mgr -> SQL service Log On = SENARATH\svc_mssql / SqlSvc123! -> restart
                setspn -A MSSQLSvc/SQL01.senarath.local:1433 SENARATH\svc_mssql
                (optional) enable xp_cmdshell per guide p.27 ; whoami /priv => SeImpersonate ; prevention = log only
WEB01:          /etc/hosts SQL01.senarath.local <SQL01_IP> ; DB_HOST stays the name ; native install (DEPLOY.md)
NOTE:           attack execution = tester tooling, per the guide
```
