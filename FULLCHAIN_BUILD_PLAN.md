# Senarath Bank Lab - Full-chain build plan (environment provisioning)

> **LAB ENVIRONMENT - isolated synthetic lab.** This document is the
> **environment build** plan for the full 17-stage chain, following the
> *Senarath Bank Lab Build Guide* Part B (pages 25-37). It tells you **which
> machines to stand up, in what order, with which accounts, services and
> intentional misconfigurations**, so the documented weaknesses **V1-V6 and
> D1-D6 are in place and ready**.
>
> **Scope:** this provisions the *targets*. It does **not** include attack
> execution. As the build guide states, "the offensive security tester supplies
> its own tooling" for the elevation (D2), credential recovery (D5) and
> extraction / ticket forging (D6) steps - those are performed during the event,
> not built here. Each weakness below lists its **build config** and the guide's
> **acceptance test** (what proves it's ready), nothing more.
>
> Keep the whole lab **isolated from production** (guide p.10). Never reuse any
> of these synthetic passwords anywhere real.

---

## 1. Hosts to stand up

| Host | Zone | OS | Role |
|------|------|----|------|
| **WAF01** | DMZ 1 | your WAF/CDN appliance or laptop | Public HTTPS entry; upload filtering (web route only) |
| **WEB01** | DMZ 1 | Ubuntu + Docker | Origin: corporate + ebanking + nginx gateway (**already built** - `MASTER_RUNBOOK.md`) |
| **DC01** | Internal `senarath.local` | Windows Server 2019/2022 | Domain controller (the lab forest) |
| **SQL01** | Internal `senarath.local` | Windows Server + SQL Server 2022 | `SenarathLab` DB; domain-joined |
| **HR01** | Internal `senarath.local` | **Legacy** Windows, **no EDR** | Legacy HR server (guide p.30-31) |

Network (guide p.9-11): **DMZ 1** and **Internal `senarath.local`** are
**directly connected with no internal firewall**. SQL01, HR01, DC01 share **one
internal subnet** and the `senarath.local` domain. The WAF sits only on the
public web route.

Service connections to provision (guide p.11):

| Path | Source → Destination | Ports |
|------|----------------------|-------|
| Web → SQL | WEB01 → SQL01 | TCP 1433 |
| SQL → HR | SQL01 → HR01 | SMB 445 / RDP 3389 / WinRM 5985 |
| HR → DC | HR01 → DC01 | SMB 445 / Kerberos 88 / LDAP 389 |

---

## 2. Accounts to create (fixed synthetic values, guide p.13-14)

Create these on the domain (DC01) except where noted. All passwords are
intentionally simple lab values.

| Account | Password | Where / role |
|---------|----------|--------------|
| `SENARATH\svc_mssql` | `SqlSvc123!` | Domain account that **runs SQL Server on SQL01** (D1/D2) |
| `lab_app` (SQL login) | `DbLab123!` | **SQL login** on SQL01 (created by `db/01_schema.sql`); sysadmin for D1; the V6 creds |
| `SENARATH\svc_hr` | `Password123!` | Domain user; Kerberoastable SPN; local admin on HR01 (D3/D4) |
| `SENARATH\lab_da` | `LabAdmin123!` | **Domain Admin**; its material is planted on HR01 (D5) and used against DC01 (D6) |
| `HR01\lab_local` | `LocalLab123!` | HR01 **local** administrator (stage-15 verification) |
| `svc_web` | `WebSvc123!` | Origin app process identity on WEB01 (reads `DB_USER/DB_PASSWORD/DB_HOST`) |

> **`svc_web` in our Docker build:** the ebanking container runs its web process
> as `www-data` and reads the DB creds from the environment (V6), which is the
> same posture the guide assigns to `svc_web`. You don't need to create a
> separate `svc_web` OS user for the Dockerized origin; the container *is* the
> origin process identity.

---

## 3. Build order  (do them in this sequence)

```
1. DC01   build the domain senarath.local, create the domain accounts above
2. SQL01  join domain → run SQL as svc_mssql → DB+seed → lab_app sysadmin → xp_cmdshell posture (D1/D2)
3. HR01   legacy OS, no EDR → join domain → svc_hr local admin → plant DA material (D4/D5)
4. WEB01  web tier, connect to SQL01 (already built; just point DB_HOST at SQL01)
5. WAF01  front the public web route; keep origin IP reachable (V2)
6. Validate every internal service connection, then the acceptance tests
```

---

## 4. DC01 - domain controller

**Build config**
1. Install Windows Server; promote to a domain controller for a **new forest
   `senarath.local`** (NetBIOS `SENARATH`). Standalone lab forest (guide p.9).
2. Create the domain accounts from section 2 (`svc_mssql`, `svc_hr`, `lab_da`).
   Make `lab_da` a member of **Domain Admins**.
3. DNS: DC01 is the DNS server for the internal subnet; add host records for
   `SQL01`, `HR01`, and map `hrapp.senarath.local` → HR01 (guide p.15).
4. **D6 posture (readiness only):** ensure `lab_da` can reach DC01 on SMB 445 /
   Kerberos 88 / LDAP 389, and turn on **auditing** for logon, directory-service
   access and Kerberos so the run is recorded (guide p.33). No weakness is added
   for the forge itself - a Domain Admin can already read the directory; this
   step only ensures the path and DC access exist. *Extraction and ticket
   forging are performed by the tester's own tooling during stage 17.*

**Acceptance (guide p.33, p.37):** the environment is ready when a recovered DA
can reach the DC directly and the DC audits the activity.

---

## 5. SQL01 - MSSQL host  (D1, D2)

Follow **`SQL01_PREPARE.md`** for the base install (SQL 2022 Mixed Mode, TCP/IP
+ 1433, firewall, `01_schema.sql` + `02_seed_customers.sql`). Then add the
full-chain posture:

**D1 - xp_cmdshell + privileged service account (guide p.26-27)**
1. **Join SQL01 to `senarath.local`** (point DNS at DC01, then domain-join).
2. **Run SQL Server as the domain account** `SENARATH\svc_mssql` (not a virtual/
   managed account): Configuration Manager → SQL Server service → **Log On** →
   *This account* → `SENARATH\svc_mssql` / `SqlSvc123!` → restart. (This service
   context is what carries SeImpersonate for D2 and is Kerberoastable in D3's
   pattern.)
3. Register the service SPN so Kerberos works:
   ```
   setspn -A MSSQLSvc/SQL01.senarath.local:1433 SENARATH\svc_mssql
   ```
4. **Grant the app login sysadmin** (the V6 login maps here):
   ```sql
   ALTER SERVER ROLE sysadmin ADD MEMBER lab_app;
   ```
5. **xp_cmdshell posture:** the guide builds the instance so `xp_cmdshell` can be
   enabled (p.26-27). It is a configuration toggle that the testers enable during
   stage 10; grant (4) is what makes that possible. (If you choose to pre-enable
   it per p.27, that is the documented `sp_configure 'xp_cmdshell',1` toggle -
   leave that action to your run plan.)
6. TCP/IP + remote connections on 1433 so WEB01 logs in directly (already done
   in `SQL01_PREPARE.md`).

**D2 - SeImpersonate posture (guide p.28)**
- No special hole is added. Because SQL runs as the domain **service account**
  (step 2), its process token already carries `SeImpersonatePrivilege`. Confirm
  in that context: `whoami /priv` shows **SeImpersonatePrivilege = Enabled**.
- Leave host-based prevention **off / log-only** so token abuse is recorded, not
  blocked. *The elevation tooling is the tester's; this step only ensures the
  privilege and posture are present.*

**Acceptance (guide p.26, p.28):** from an MSSQL session a benign command runs
and returns the SQL **service-account** context; and `whoami /priv` in that
context shows SeImpersonate enabled.

---

## 6. HR01 - legacy HR server  (D3 target, D4, D5)

**OS choice (guide p.30-31):** pick and record **one** legacy OS - Windows
Server 2008 SP2 (32-bit, non-R2) or Windows 2000 Server - and leave it **off the
EDR fleet (no sensor)**.

**D4 - legacy production HR server (guide p.30)**
1. Build HR01 on the chosen legacy OS, **no EDR sensor**.
2. **Join HR01 to `senarath.local`**, same internal subnet as SQL01 and DC01.
3. Add the Kerberoast service account **`SENARATH\svc_hr`** to HR01's **local
   Administrators** group.
4. Verify **SQL01 → HR01** connectivity on SMB 445 / RDP 3389 / WinRM 5985.

**D3 - Kerberoastable service account (guide p.29)** *(the account lives in AD,
registered against HR)*
1. `SENARATH\svc_hr` is a normal domain user with a **weak password**
   (`Password123!`, on a common wordlist) so a roasted ticket cracks offline.
2. Register its SPN so it is Kerberoastable:
   ```
   setspn -A HR/hrapp.senarath.local SENARATH\svc_hr
   ```
3. Leave the account on **RC4** (default `msDS-SupportedEncryptionTypes`) so the
   TGS is easy to crack. *The roasting + offline crack are the tester's steps.*

**D5 - recoverable Domain Admin credential on the legacy box (guide p.32)**
- Plant DA credential material on HR01 by **one** chosen mechanism, and **record
  which one** (it decides the tooling):
  - an interactive DA logon left cached, **or**
  - a live DA RDP session left connected, **or**
  - a Windows **service / scheduled task set to run as `SENARATH\lab_da`**
    (stores the credential in LSA secrets).
- A Domain Admin touching a legacy box is itself the intentional
  misconfiguration - just make the chosen mechanism reproducible. *Recovering the
  material (local hash dump / DA recovery) is the tester's tooling.*

**Acceptance (guide p.29, p.30, p.32):** a Kerberoast yields `svc_hr`'s TGS and
it cracks offline; `svc_hr` authenticates to HR01 with local-admin rights; and
from local-admin context DA credential material valid in the domain is
recoverable.

---

## 7. WEB01 - origin (already built)

No change beyond pointing it at SQL01. In `senarath-ebanking/docker-compose.yml`
set the mapping to this SQL01's address and keep `DB_HOST=SQL01.senarath.local`:
```yaml
  ebanking:
    extra_hosts:
      - "SQL01.senarath.local:<SQL01_IP>"
```
Then `docker compose up -d --build` (corporate, then ebanking). Full steps:
`MASTER_RUNBOOK.md`. The web weaknesses V1-V6 are already built into the app
(`VULNERABILITIES.md`).

---

## 8. WAF01 - public web route  (V2 posture)

- Point `ebanking.senarathbank.com` / `www.senarathbank.com` at **WAF01**; the WAF
  forwards to WEB01 as origin. Enforce **upload filtering only at the WAF**, never
  at the origin (guide p.18, p.22).
- Make the **origin IP discoverable** and have WEB01 accept the request directly
  with the right Host header - that direct origin route (WAF out of path) is
  weakness **V2**.

---

## 9. Validate the environment is full-chain-ready

Run these **readiness** checks (not attacks):

```
[WEB01→SQL01]  docker exec senarath-ebanking bash -lc '(echo >/dev/tcp/SQL01.senarath.local/1433)&&echo ok'
[SQL01]        whoami /priv        # service context shows SeImpersonatePrivilege = Enabled
[SQL01→HR01]   Test-NetConnection HR01 -Port 445   (and 3389 / 5985)
[HR01→DC01]    Test-NetConnection DC01 -Port 445   (and 88 / 389)
[AD]           setspn -L SENARATH\svc_mssql ;  setspn -L SENARATH\svc_hr   # SPNs present
[AD]           svc_hr is in HR01 local Administrators ; lab_da in Domain Admins
```

Then the guide's acceptance tests (p.36-37) describe what each stage should
achieve when a tester walks the chain with their own tooling. Build is "done"
when each documented weakness is **present and in the posture above** - the
tester validates the exploitation during the event.

---

## 10. Develop it incrementally (your plan)

You can bring this up in layers and still have a working demo at each step:

```
Layer 1 (now):  WEB01 + SQL01 (standalone or domain-joined) → app works, V1-V6 ✓
Layer 2:        Stand up DC01, join SQL01, run SQL as svc_mssql, lab_app sysadmin → D1/D2 ready
Layer 3:        Build HR01 (legacy, no EDR), join domain, svc_hr + SPN, local admin → D3/D4 ready
Layer 4:        Plant DA material on HR01, enable DC auditing + DA→DC path → D5/D6 ready
Layer 5:        Put WAF01 in front; make origin IP discoverable → V2 public posture
```
Each layer only adds provisioning; nothing already working breaks.

---

### One-screen summary
```
DC01   new forest senarath.local ; create svc_mssql, svc_hr, lab_da(DA) ; DNS+records ; DC auditing
SQL01  join domain ; SQL runs as svc_mssql ; setspn MSSQLSvc/SQL01...:1433
       db/01_schema.sql + 02_seed ; ALTER SERVER ROLE sysadmin ADD MEMBER lab_app
       whoami /priv => SeImpersonate ; host prevention = log only
HR01   legacy OS, NO EDR ; join domain ; svc_hr -> local admins ; setspn HR/hrapp.senarath.local
       plant ONE DA-cred mechanism (record it)
WEB01  extra_hosts SQL01.senarath.local:<IP> ; DB_HOST stays the name ; compose up
WAF01  public name -> WAF -> WEB01 origin ; origin IP discoverable (V2) ; upload filter at WAF only
NOTE   attack execution (roast/elevate/extract/forge) = tester tooling, per the guide
```
