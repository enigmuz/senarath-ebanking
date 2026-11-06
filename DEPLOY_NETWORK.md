# Senarath Bank — Network & Ubuntu deployment (full step-by-step)

> **LAB ENVIRONMENT.** Fictional bank, synthetic data, deliberately vulnerable
> (V1–V6). Deploy only on an isolated, time-boxed lab. This is the **networking +
> operations** companion to [`DEPLOY.md`](DEPLOY.md): the domain-registrar steps,
> the network diagram, every port to open and where, and the complete native
> Ubuntu build with copy-paste commands. No Docker.

---

## 1. Network diagram & trust boundaries

```
                         INTERNET
                            │
            DNS: senarathbank.com, www,                    (A records → PUBLIC_IP)
                 ebanking, www.ebanking
                            │
                            ▼
                 ┌──────────────────────┐
                 │  Router / Firewall    │  forward ONLY TCP 80, 443 → WEB01_IP
                 │  (public edge)        │  (optional: WAF01 appliance in front)
                 └──────────┬───────────┘
                            │  80 / 443
        ════════════════════▼═════════════════════  DMZ 1
                 ┌──────────────────────┐
                 │  WEB01  (Ubuntu)      │  Apache + PHP 8.2 + FreeTDS  (native)
                 │  corporate + ebanking │  ufw: 22 (admin), 80, 443 in
                 │  runs as www-data     │  → connects OUT to SQL01:1433
                 └──────────┬───────────┘
                            │  TCP 1433  (app → DB, V6 path)
        ════════════════════▼═════════════════════  Internal  senarath.local
       ┌─────────────┐  ┌─────────────┐  ┌──────────────────────┐
       │  DC01        │  │  SQL01       │  │  HR01 (legacy, no EDR)│
       │  Domain Ctrl │◄─┤  MSSQL 2022  │─►│  lateral target (P-B) │
       │  senarath.local │  SenarathLab │  │                      │
       └─────────────┘  └─────────────┘  └──────────────────────┘
        (DC01/HR01 only needed for the full attack chain — Part B)
```

**Boundaries that matter**
- **Internet → WEB01:** only **80/443** ever cross the public edge. Nothing else.
- **DMZ → Internal:** WEB01 reaches **only `SQL01:1433`**. WEB01 never needs to
  reach DC01/HR01 — those are the AD team's internal targets.
- **SQL01 stays internal** — never port-forward 1433 to the internet.
- Per the build guide the internal zone is **directly connected (no internal
  firewall)**; the internal ports in §4 are what must be *reachable*, which on an
  unfiltered segment they already are.

---

## 2. Addressing plan — fill these in once

| Name | Example | Meaning |
|------|---------|---------|
| `PUBLIC_IP` | `203.0.113.10` | Internet-facing IP (router WAN or VPS) |
| `WEB01_IP` | `10.10.10.20` | WEB01 LAN IP (static) |
| `GATEWAY_IP`| `10.10.10.1` | WEB01's default gateway (router) |
| `SQL01_IP` | `10.10.20.11` | MSSQL server LAN IP |
| `DC01_IP` | `10.10.20.10` | Domain controller (Part B only) |
| `HR01_IP` | `10.10.20.12` | Legacy HR host (Part B only) |
| `EMAIL` | `you@…` | Let's Encrypt registration/renewal |

Get them: `curl -s ifconfig.me` (PUBLIC_IP from WEB01), `hostname -I` (WEB01_IP),
`ip route | grep default` (GATEWAY_IP).

---

## 3. Port matrix — what to open and where

### 3a. Web deployment (the minimum to go live)
| From | To | Port/Proto | Purpose | Open it on |
|------|----|-----------|---------|-----------|
| Internet (any) | WEB01 | **TCP 80** | HTTP → HTTPS redirect **+ Let's Encrypt ACME** | router forward **and** WEB01 `ufw` |
| Internet (any) | WEB01 | **TCP 443** | HTTPS (both sites, SNI) | router forward **and** WEB01 `ufw` |
| Your admin IP | WEB01 | **TCP 22** | SSH administration | WEB01 `ufw` (restrict to your IP) |
| WEB01 | SQL01 | **TCP 1433** | App → MSSQL (V6 / stage 9) | **SQL01 Windows Firewall** (inbound, allow `WEB01_IP`) |
| WEB01 | Internet | TCP 443 (out) | `apt`, `git`, certbot ACME | outbound (usually already allowed) |

> Let's Encrypt validates over **inbound TCP 80** — it must be reachable from the
> internet, or use the DNS-01 / Cloudflare options in §9.

### 3b. Internal AD (only if you build the full chain — Part B)
On the unfiltered internal segment these are reachable already; this is the
reference if any host firewall is on.

| From | To | Ports | Purpose |
|------|----|-------|---------|
| SQL01 | DC01 | 88, 135 + dynamic 49152-65535, 389, 445, 464, 636, 53 (DNS) | domain join, Kerberos, LDAP, RPC |
| SQL01 | HR01 | 445 (SMB), 3389 (RDP), 5985 (WinRM) | lateral move (stage 14) |
| attacker/analyst box | WEB01 | 80 / 443 | the attack entry point |

Never open DC01/HR01/SQL01 to the internet.

---

## 4. Connect the domain at your registrar (DNS)

Do this **first** — propagation can take minutes to a few hours.

1. Sign in to the registrar that holds **`senarathbank.com`** (Namecheap, GoDaddy,
   Google/Squarespace, Cloudflare, etc.).
2. Open the domain's **DNS management** page (labelled "DNS", "Advanced DNS",
   "Manage DNS", or "DNS records").
3. **Delete** any default parking/forwarding A or CNAME records on `@` and `www`.
4. **Add four A records**, all pointing at `PUBLIC_IP`:

   | Type | Host / Name | Value | TTL |
   |------|-------------|-------|-----|
   | A | `@`  (or blank / `senarathbank.com`) | `PUBLIC_IP` | 300 / Automatic |
   | A | `www` | `PUBLIC_IP` | 300 |
   | A | `ebanking` | `PUBLIC_IP` | 300 |
   | A | `www.ebanking` | `PUBLIC_IP` | 300 |

   (Some panels want the full host `ebanking.senarathbank.com`, others just the
   label `ebanking` — both mean the same record.)
5. Save. Keep **TTL low (300s)** while setting up so changes apply fast.

**Verify from anywhere** (and from a network *outside* your LAN, e.g. phone on
mobile data):
```bash
dig +short senarathbank.com
dig +short www.senarathbank.com
dig +short ebanking.senarathbank.com
dig +short www.ebanking.senarathbank.com
# every one must return PUBLIC_IP
nslookup ebanking.senarathbank.com    # Windows equivalent
```

> **ISP blocking 80/443?** Many home ISPs block inbound web ports. Test from an
> outside network: `curl -I http://senarathbank.com`. If it never connects, use a
> business line, a cloud VPS, or a **Cloudflare Tunnel** (§9).

---

## 5. Router / edge — port forwarding

If WEB01 is behind a router (not a cloud VPS with a public IP directly on it):
- Forward **TCP 80 → `WEB01_IP`:80**
- Forward **TCP 443 → `WEB01_IP`:443**
- **Forward nothing else.** Do **not** forward 22, 1433, 3389, etc. to the internet.

(On a cloud VPS, instead open 80/443/22 in the provider's security group; the VPS
public IP *is* `PUBLIC_IP`.)

---

## 6. WEB01 — Ubuntu server build (every step)

### 6.1 Base OS
Install **Ubuntu Server 22.04 or 24.04 LTS** (minimal, OpenSSH enabled).

### 6.2 Give WEB01 a static LAN IP (netplan)
Find the interface name with `ip -br link` (e.g. `ens18`). Edit the netplan file
(e.g. `/etc/netplan/00-installer-config.yaml`):
```yaml
network:
  version: 2
  ethernets:
    ens18:                      # ← your interface
      addresses: [10.10.10.20/24]   # ← WEB01_IP/prefix
      routes:
        - to: default
          via: 10.10.10.1       # ← GATEWAY_IP
      nameservers:
        addresses: [1.1.1.1, 8.8.8.8]
```
```bash
sudo netplan apply
ip -br addr        # confirm WEB01_IP is set
```

### 6.3 Update + base tools
```bash
sudo apt-get update && sudo apt-get -y upgrade
sudo apt-get install -y git curl ca-certificates
sudo timedatectl set-timezone Asia/Colombo       # optional
```

### 6.4 Get the code
```bash
sudo git clone https://github.com/enigmuz/senarath-corporate.git /var/www/senarath-corporate
sudo git clone https://github.com/enigmuz/senarath-ebanking.git  /var/www/senarath-ebanking
```
(Private repos: `https://<user>:<token>@github.com/enigmuz/<repo>.git`.)

### 6.5 Install the stack (one command)
```bash
sudo SQL01_IP=<SQL01_IP> bash /var/www/senarath-ebanking/deploy/install-native.sh
```
Installs **Apache + PHP 8.2 + php8.2-sybase (pdo_dblib) + FreeTDS**, enables
prefork+mod_php, sets FreeTDS **TDS 7.4**, installs the vhosts + PHP ini, maps
`SQL01.senarath.local → SQL01_IP` in `/etc/hosts`, fixes `uploads/` (V5) and
`.env` (V6) permissions, enables the site, reloads Apache.

<details><summary>Manual equivalent</summary>

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

### 6.6 Host firewall (ufw)
```bash
sudo ufw allow from <YOUR_ADMIN_IP> to any port 22 proto tcp   # or: sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status verbose
```
WEB01 only **listens** on 22/80/443 and **dials out** to SQL01:1433 — never open
1433 inbound here.

---

## 7. TLS certificate (go live on HTTPS)

With DNS live (§4) and port 80 reachable from the internet:
```bash
sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache \
  -d senarathbank.com -d www.senarathbank.com \
  -d ebanking.senarathbank.com -d www.ebanking.senarathbank.com \
  -m <EMAIL> --agree-tos --no-eff-email --redirect
sudo certbot renew --dry-run      # confirm auto-renewal works
```
`--apache` writes the `:443` vhosts, adds the HTTP→HTTPS redirect, installs the
renewal timer. One cert covers all four names.

---

## 8. SQL01 side + end-to-end verification

**On SQL01** (already built): SQL Server Configuration Manager → enable **TCP/IP**,
listen on **1433**, restart the service; **Windows Firewall** → inbound rule
allowing **TCP 1433 from `WEB01_IP`** only. (DB build / `lab_app` / `SenarathLab`
and the full-chain AD prep: `DEPLOY.md` §2 and `SQL01_PREPARE.md`.)

**Verification matrix — run top to bottom:**
```bash
# A. DNS resolves (any machine)
dig +short ebanking.senarathbank.com                      # → PUBLIC_IP

# B. Edge reachable (outside network)
curl -I http://senarathbank.com                           # 301 → https
curl -I https://ebanking.senarathbank.com                 # 200, valid cert

# C. WEB01 → SQL01 path (ON WEB01)
php -m | grep -i pdo_dblib                                # driver present
nc -vz SQL01.senarath.local 1433                          # open
# full login smoke:
curl -s -X POST https://ebanking.senarathbank.com/api/login.php \
  -H 'Content-Type: application/json' -d '{"username":"customer01","password":"Welcome123!"}'

# D. Browser: https://senarathbank.com (corporate) ; https://ebanking.senarathbank.com → customer01/Welcome123!
```

---

## 9. If you can't expose 80/443 — Cloudflare Tunnel (no port-forward)

1. Move `senarathbank.com` DNS to **Cloudflare** (change nameservers at the registrar).
2. Recreate the four A records in Cloudflare (proxied).
3. On WEB01: install `cloudflared`, authenticate, create a tunnel, and map:
   - `senarathbank.com`, `www.senarathbank.com` → `http://localhost:80`
   - `ebanking.senarathbank.com`, `www.ebanking.senarathbank.com` → `http://localhost:80`
4. Cloudflare provides public TLS (SSL mode **Full**); Apache can then stay HTTP-only.
   No router forwarding or public IP needed. Tell me if you go this route and I'll
   hand you the exact `cloudflared` config.

---

## 10. Troubleshooting (network-focused)

| Symptom | Cause / fix |
|---|---|
| `dig` returns nothing / old IP | DNS not propagated or wrong record → recheck §4; lower TTL; wait |
| certbot "challenge failed" | port 80 not reachable from internet → router forward (§5) / ISP block (§4) / ufw (§6.6) |
| site loads on IP but not domain | A record missing/wrong; `www.*` needs its own record / `ServerAlias` |
| HTTPS cert warning | reached a name not on the cert → cert must list all **four** names (§7) |
| ebanking "briefly unavailable" | WEB01 can't reach SQL01 → `nc -vz SQL01.senarath.local 1433`; fix `/etc/hosts`/`.env`; SQL01 firewall allows 1433 from `WEB01_IP` |
| MSSQL login protocol error | set `tds version = 7.4` under `[global]` in `/etc/freetds/freetds.conf`, reload Apache |
| 80/443 "address already in use" | another service → `sudo ss -tlnp | grep ':80\|:443'`, stop it |
| SSH locked out after ufw | you restricted 22 to an IP that changed → fix at console: `sudo ufw allow 22/tcp` |

### One-screen summary
```
DNS      registrar → A @,www,ebanking,www.ebanking → PUBLIC_IP ; verify with dig
EDGE     router forward TCP 80,443 → WEB01_IP   (nothing else)
WEB01    static IP (netplan) → git clone both repos → install-native.sh → ufw 22,80,443
TLS      certbot --apache -d (all 4 names) --redirect
SQL01    TCP/IP 1433 on ; firewall allow WEB01_IP ; (WEB01→SQL01 only)
VERIFY   dig → curl -I https → php -m|grep pdo_dblib → nc -vz SQL01 1433 → login
```
