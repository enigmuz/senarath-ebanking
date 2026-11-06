# Senarath Bank - Deploy both sites on Ubuntu (native, no Docker)

> **LAB ENVIRONMENT.** Senarath Bank is a **fictional** institution; all data is
> **synthetic**. The e-banking app is **deliberately vulnerable** (weaknesses
> **V1-V6**, see [`VULNERABILITIES.md`](VULNERABILITIES.md)) for the CrowdStrike
> demonstration. Deploy only on an **isolated, time-boxed** lab network.

This is the **single, from-scratch** guide. Both sites run **natively** on one
Ubuntu host (WEB01) under **Apache + PHP 8.2 + FreeTDS** - **no Docker, no
containers**. WEB01 is a real host on the DMZ, so after the web RCE the shell is
on the actual box and the pivot to the internal MSSQL server is plain TCP -
exactly the topology the build guide assumes.

```
        Internet / DNS                         Internal (senarath.local)
 senarathbank.com ─┐                        ┌───────────────────────────┐
 www ──────────────┼─► WEB01  (DMZ, Ubuntu) │  SQL01  (MSSQL 2019/2022)  │
 ebanking ─────────┤   Apache + PHP 8.2 ────┼──► TCP 1433  SenarathLab   │
 www.ebanking ─────┘   corporate + ebanking │     lab_app / DbLab123!    │
                        (ports 80 / 443)     └───────────────────────────┘
          corporate  ◄─ Host: senarathbank.com / www
          ebanking   ◄─ Host: ebanking.senarathbank.com / www.ebanking
```

Two repos:
- `senarath-corporate` → the marketing site (`senarathbank.com`, `www`)
- `senarath-ebanking`  → the online-banking app (`ebanking.senarathbank.com`, `www.ebanking`)

---

## 0. Prerequisites & fill-in

- **WEB01**: a plain **Ubuntu Server 22.04 or 24.04 LTS** box, static LAN IP, can
  reach SQL01 on the LAN and the internet on 80/443.
- **SQL01**: your MSSQL server (already built on its own machine).
- **Domain**: `senarathbank.com` at a registrar whose DNS you control.

| Placeholder | Meaning | How to get it |
|---|---|---|
| `PUBLIC_IP` | WEB01's internet-facing IP | router WAN IP (`curl -s ifconfig.me`) or VPS IP |
| `WEB01_IP` | WEB01's LAN IP | `hostname -I` on WEB01 |
| `SQL01_IP` | MSSQL server's LAN IP | from SQL01 |
| `EMAIL` | your email for Let's Encrypt | your address |

---

## 1. Push the two repos (from your workstation)

The repos already exist under the `enigmuz` owner. From each project folder, commit
and push your latest changes:

```bash
# corporate
cd senarath-corporate
git add -A && git commit -m "Native deploy" && git push origin main

# ebanking
cd ../senarath-ebanking
git add -A && git commit -m "Native deploy" && git push origin main
```
(First-time remotes: `git remote add origin https://github.com/enigmuz/<repo>.git`.)

You will **clone/pull** these on WEB01 in step 4, and re-pull on every update
(step 9).

---

## 2. SQL01 - make the database ready and reachable  **[SQL01]**

The app needs the `SenarathLab` database, the `lab_app` login, and TCP 1433 open
to WEB01. If you already built this, just confirm it; otherwise run the two
scripts shipped in the ebanking repo (`db/`):

```sql
-- In SQL Server Management Studio / sqlcmd, as sysadmin:
:r 01_schema.sql        -- creates SenarathLab, tables, and the lab_app login (DbLab123!)
:r 02_seed_customers.sql-- 205 synthetic customers, accounts, ~3,400 txns
```
Then on SQL01:
1. **SQL Server Configuration Manager** → enable **TCP/IP** protocol → set it to
   listen on **1433** → restart the SQL service.
2. SQL auth: ensure **Mixed Mode** is on and `lab_app` is enabled.
3. **Windows Firewall**: allow inbound **TCP 1433 from `WEB01_IP`** only.

> Deeper SQL01 + Active Directory prep for the full attack chain (service
> accounts, `xp_cmdshell`, SPNs, D1-D6) is a separate workstream - see
> [`SQL01_PREPARE.md`](SQL01_PREPARE.md) and
> [`FULLCHAIN_BUILD_PLAN.md`](FULLCHAIN_BUILD_PLAN.md).

Quick reachability check you'll repeat from WEB01 in step 8:
`nc -vz <SQL01_IP> 1433`.

---

## 3. DNS - point the domain at WEB01  *(do first; propagation takes time)*

At your registrar's DNS panel for `senarathbank.com`, create four **A records**,
all → `PUBLIC_IP`:

| Type | Name/Host | Value |
|------|-----------|-------|
| A | `@` (root) | `PUBLIC_IP` |
| A | `www` | `PUBLIC_IP` |
| A | `ebanking` | `PUBLIC_IP` |
| A | `www.ebanking` | `PUBLIC_IP` |

If WEB01 sits behind a router, **port-forward only** `TCP 80` and `TCP 443` to
`WEB01_IP`. Verify before TLS:
```bash
dig +short senarathbank.com www.senarathbank.com ebanking.senarathbank.com www.ebanking.senarathbank.com
# every line must return PUBLIC_IP
```

---

## 4. WEB01 - clone both repos and install  **[WEB01]**

```bash
sudo apt-get update && sudo apt-get install -y git
sudo git clone https://github.com/enigmuz/senarath-corporate.git /var/www/senarath-corporate
sudo git clone https://github.com/enigmuz/senarath-ebanking.git  /var/www/senarath-ebanking

# one-command native install (PHP 8.2 + Apache + FreeTDS + vhosts + perms)
sudo SQL01_IP=<SQL01_IP> bash /var/www/senarath-ebanking/deploy/install-native.sh
```
The installer sets up:
- **Apache + PHP 8.2 + `php8.2-sybase` (pdo_dblib) + FreeTDS** (via the ondrej PPA, so PHP 8.2 on any Ubuntu), prefork + mod_php, FreeTDS **TDS 7.4**.
- The **name-based vhosts** (`deploy/apache/senarath.conf`) → corporate & ebanking docroots, and the PHP ini (`deploy/php/zz-ebanking.ini`).
- `/etc/hosts`: `SQL01.senarath.local → SQL01_IP`.
- `uploads/` owned by `www-data` and PHP-executable (**V5**); `.env` readable by the web user, kept **above** the webroot so it's never served over HTTP (**V6**).

> **Webroot = each repo's `src/` folder.** The banking `.env` lives at
> `/var/www/senarath-ebanking/.env` (one level above the webroot) and
> `src/config.php` reads it automatically.

<details><summary>Manual equivalent (if you don't want the installer)</summary>

```bash
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update
sudo apt-get install -y apache2 libapache2-mod-php8.2 \
  php8.2-cli php8.2-sybase php8.2-mbstring php8.2-xml freetds-bin freetds-common
sudo a2enmod rewrite headers ; sudo a2dismod mpm_event ; sudo a2enmod mpm_prefork php8.2
sudo sed -i '/^\[global\]/a \\ttds version = 7.4' /etc/freetds/freetds.conf
sudo cp /var/www/senarath-ebanking/deploy/apache/senarath.conf /etc/apache2/sites-available/senarath.conf
sudo cp /var/www/senarath-ebanking/deploy/php/zz-ebanking.ini   /etc/php/8.2/apache2/conf.d/zz-ebanking.ini
sudo a2dissite 000-default ; sudo a2ensite senarath
echo "<SQL01_IP>   SQL01.senarath.local" | sudo tee -a /etc/hosts
sudo mkdir -p /var/www/senarath-ebanking/src/uploads
sudo chown -R www-data:www-data /var/www/senarath-ebanking/src/uploads && sudo chmod -R 0775 /var/www/senarath-ebanking/src/uploads
sudo chown www-data:www-data /var/www/senarath-ebanking/.env && sudo chmod 0640 /var/www/senarath-ebanking/.env
sudo apache2ctl configtest && sudo systemctl reload apache2
```
</details>

---

## 5. Point the app at your SQL server  **[WEB01]**

Step 4 mapped `SQL01.senarath.local` in `/etc/hosts`, and the app keeps
`DB_HOST=SQL01.senarath.local` (from `.env`). To hardcode the IP instead:
```bash
sudo sed -i 's/^DB_HOST=.*/DB_HOST=<SQL01_IP>/' /var/www/senarath-ebanking/.env
```
Leave `DB_USER=lab_app`, `DB_PASSWORD=DbLab123!`, `DB_NAME=SenarathLab` as-is (**V6**).

## 6. Firewall  **[WEB01]**
```bash
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
```
Only 80/443 inbound. WEB01 **connects out** to SQL01:1433 - never open 1433 here.

---

## 7. Go live with HTTPS - one command  **[WEB01]**

With DNS live (step 3) and port 80 reachable:
```bash
sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache \
  -d senarathbank.com -d www.senarathbank.com \
  -d ebanking.senarathbank.com -d www.ebanking.senarathbank.com \
  -m <EMAIL> --agree-tos --no-eff-email --redirect
```
`--apache` writes the `:443` vhosts, adds the HTTP→HTTPS redirect, and installs a
renewal timer. One certificate covers all four names.

---

## 8. Verify end to end  **[WEB01]**
```bash
php -m | grep -i pdo_dblib                       # MSSQL driver present
nc -vz SQL01.senarath.local 1433                 # WEB01 → SQL01 path
curl -s -o /dev/null -w "corp %{http_code}\n" -H 'Host: senarathbank.com'        http://127.0.0.1/
curl -s -o /dev/null -w "bank %{http_code}\n" -H 'Host: ebanking.senarathbank.com' http://127.0.0.1/index.php
```
Then in a browser:
- `https://senarathbank.com` → corporate site, valid padlock.
- `https://ebanking.senarathbank.com` → online banking; log in `customer01 / Welcome123!`.

If ebanking shows "briefly unavailable", WEB01 can't reach SQL01 → recheck
`/etc/hosts` / `.env` and SQL01's firewall (step 2).

---

## 9. Update workflow - push locally, pull on the server

Your day-to-day loop once it's live:
```bash
# on your workstation
git add -A && git commit -m "..." && git push        # in each repo

# on WEB01
cd /var/www/senarath-corporate && sudo git pull
cd /var/www/senarath-ebanking  && sudo git pull
sudo systemctl reload apache2                          # pick up changes
```
No rebuild step - PHP is interpreted, so a `git pull` + Apache reload is the whole
deploy. (After pulling ebanking, re-assert uploads perms if git reset them:
`sudo chown -R www-data:www-data /var/www/senarath-ebanking/src/uploads`.)

---

## 10. How the attack chain runs (native)

```
Recon   senarathbank.com → find ebanking.senarathbank.com + leaked cred   (V1)
Login   customer01 / Welcome123!                                          (V3)
2FA     pre-OTP token already authenticated                               (V4)
Upload  POST a .php to /upload.php → stored in src/uploads/, executable    (V5)
RCE     GET /uploads/shell.php → runs as www-data ON WEB01 ITSELF
Loot    read /var/www/senarath-ebanking/.env  (or `env`)                  (V6)
          lab_app / DbLab123! @ SQL01.senarath.local
Pivot   from the WEB01 shell, direct over the LAN to SQL01:1433
          → xp_cmdshell (D1) → SeImpersonate→SYSTEM (D2) → AD stages (Part B)
```
No container means the `www-data` shell is on the real WEB01 host - reverse
shells, `impacket`/`mssqlclient`, and the reach to SQL01 behave exactly as the
build guide draws it. No web shell is shipped (guide p.23); the tester supplies
the payload. V5 proof file: see [`VULNERABILITIES.md`](VULNERABILITIES.md).

---

## 11. Operate, troubleshoot, safety

- **Autostart:** Apache is a systemd service (`sudo systemctl enable apache2`) - the
  sites return after reboot.
- **Idle:** `sudo systemctl stop apache2` when not demoing. Isolated, time-boxed lab.
- **Reset V5 uploads:** `sudo rm -f /var/www/senarath-ebanking/src/uploads/*.php`

| Symptom | Fix |
|---|---|
| `pdo_dblib` missing from `php -m` | `sudo apt-get install -y php8.2-sybase && sudo systemctl reload apache2` |
| Login OK but DB pages 503 | WEB01 can't reach SQL01 → `nc -vz SQL01.senarath.local 1433`; fix `/etc/hosts`/`.env`; SQL01 firewall allows 1433 from `WEB01_IP` |
| MSSQL login protocol error | ensure `tds version = 7.4` under `[global]` in `/etc/freetds/freetds.conf`, reload Apache |
| `.php` upload downloads instead of running | PHP engine disabled for that dir - nothing must disable PHP under `src/uploads/` (**V5** keeps it executable) |
| 80/443 already in use | old service (nginx?) → `sudo ss -tlnp | grep ':80\|:443'` and stop it |
| `www.*` 404s but root works | missing `ServerAlias` or DNS record |

### One-screen summary
```
PUSH   (workstation) git push   both repos
SQL01  SenarathLab + lab_app ; TCP/IP 1433 on ; firewall allow WEB01
DNS    A @,www,ebanking,www.ebanking → PUBLIC_IP ; forward 80,443 → WEB01
WEB01  git clone both → /var/www ; sudo SQL01_IP=<ip> bash .../deploy/install-native.sh
FW     ufw 22,80,443   (never 1433 inbound)
TLS    sudo certbot --apache -d senarathbank.com -d www. -d ebanking. -d www.ebanking. --redirect
TEST   php -m|grep pdo_dblib ; nc -vz SQL01.senarath.local 1433 ; browse → customer01/Welcome123!
UPDATE (workstation) push → (WEB01) git pull + systemctl reload apache2
```
