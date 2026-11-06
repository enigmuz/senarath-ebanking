# Publish the Senarath sites on the internet - Ubuntu Server + HTTPS

> **LAB ENVIRONMENT.** Deploy the web tier on **Ubuntu Server** (native Docker,
> no WSL, no Windows networking issues), publish both sites on your real domain
> with HTTPS, and connect to the MSSQL/DC you already built.
>
> - `senarathbank.com` and `www.senarathbank.com` → **corporate** site
> - `ebanking.senarathbank.com` and `www.ebanking.senarathbank.com` → **online banking** app

---

## 0. Which OS - and download

**Use Ubuntu Server 24.04 LTS.** Native Docker, clean networking, no WSL layer -
this removes the port-binding problems you hit on Windows Server. Download the
**Server install** ISO (not Desktop):

- https://ubuntu.com/download/server  → "Ubuntu Server 24.04 LTS"

Install it on the web box (minimal install, enable OpenSSH), give it a **static
LAN IP**, and make sure it reaches SQL01 on the LAN.

---

## 1. How two sites on two domains actually work  (clears up "two ports")

You do **not** give each site its own custom port. Both sites listen on the two
standard web ports and are separated by **hostname**:

```
          DNS                         one server, ports 80 + 443
 senarathbank.com      ─┐
 www.senarathbank.com  ─┼──► WEB01 public IP ──► nginx gateway ──┬─► corporate  (Host: senarathbank.com / www)
 ebanking.senarathbank.com ─┘                                    └─► ebanking   (Host: ebanking.senarathbank.com)
```

- **Port 80** → redirects everything to HTTPS (and serves the cert challenge).
- **Port 443** → serves **both** sites; nginx picks which one by the hostname
  (name-based virtual hosting + TLS SNI). One certificate covers all three names.

That's the "pure website publish" model - no per-site ports to manage.

---

## 2. Fill in
| Value | Meaning | How to get it |
|-------|---------|---------------|
| `PUBLIC_IP` | WEB01's internet-facing IP | your router's WAN IP (`curl -s ifconfig.me` on WEB01) or your VPS IP |
| `WEB01_IP` | WEB01's LAN IP | `hostname -I` on WEB01 |
| `SQL01_IP` | SQL server IP (already built) | from SQL01 |
| `EMAIL` | your email for Let's Encrypt | your address (renewal notices) |

---

## 3. DNS - point the domain at the server  *(do this first; it takes time to propagate)*

At your domain registrar's DNS panel for **senarathbank.com**, create **A records**:

| Type | Name/Host | Value |
|------|-----------|-------|
| A | `@`  (root) | `PUBLIC_IP` |
| A | `www` | `PUBLIC_IP` |
| A | `ebanking` | `PUBLIC_IP` |
| A | `www.ebanking` | `PUBLIC_IP` |

If WEB01 is behind a home/office router, also **port-forward** on the router:
`Public TCP 80 → WEB01_IP:80` and `Public TCP 443 → WEB01_IP:443`. **Forward only
80 and 443.**

Verify from anywhere before continuing:
```bash
dig +short senarathbank.com
dig +short www.senarathbank.com
dig +short ebanking.senarathbank.com
dig +short www.ebanking.senarathbank.com
# all four must return PUBLIC_IP
```

> **ISP note:** some home ISPs block inbound 80/443. Test from an outside network
> (phone on mobile data): `curl -I http://senarathbank.com`. If it never
> connects, your ISP/router is blocking the ports - use a business line, a cloud
> VPS, or a **Cloudflare Tunnel** (see §9). Let's Encrypt needs port 80 reachable.

---

## 4. Install Docker  **[WEB01]**
```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
 | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER && newgrp docker
docker --version && docker compose version
```

## 5. Get the code  **[WEB01]**
```bash
mkdir -p ~/senarath && cd ~/senarath
git clone https://github.com/enigmuz/senarath-corporate.git
git clone https://github.com/enigmuz/senarath-ebanking.git
```

## 6. Point the app at your SQL server  **[WEB01]**
Keep `DB_HOST=SQL01.senarath.local` and map it to the SQL server's IP:
```bash
cd ~/senarath/senarath-ebanking
sed -i 's/SQL01.senarath.local:.*/SQL01.senarath.local:<SQL01_IP>"/' docker-compose.yml
grep -A2 extra_hosts docker-compose.yml
```

## 7. Firewall  **[WEB01]**
```bash
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
```

---

## 8. Issue the HTTPS certificate, then bring everything up  **[WEB01]**

**Order matters:** get the certificate **before** starting the gateway (the
gateway needs the cert files to exist, and certbot needs port 80 free).

```bash
# 8.1 install certbot
sudo apt-get install -y certbot

# 8.2 get ONE certificate covering all three names (port 80 is free - gateway not up yet)
sudo certbot certonly --standalone \
  -d senarathbank.com -d www.senarathbank.com \
  -d ebanking.senarathbank.com -d www.ebanking.senarathbank.com \
  -m <EMAIL> --agree-tos --no-eff-email
# -> cert saved to /etc/letsencrypt/live/senarathbank.com/

# 8.3 shared docker network (once)
docker network create senarath-net

# 8.4 corporate site (no published ports; joins the network)
cd ~/senarath/senarath-corporate && docker compose up -d --build

# 8.5 ebanking app + the nginx gateway (publishes 80/443, mounts the cert)
cd ~/senarath/senarath-ebanking  && docker compose up -d --build

docker ps     # senarath-corporate, senarath-ebanking, senarath-gateway
```

> If the gateway keeps restarting, it's almost always because the certificate
> isn't there yet - run step 8.2, then `docker compose up -d` again.

---

## 9. Verify

```bash
# from WEB01: DB path the app uses
docker exec -it senarath-ebanking bash -lc \
 '(echo > /dev/tcp/SQL01.senarath.local/1433) && echo "SQL01:1433 ok" || echo blocked'

# HTTPS on all three names (run from anywhere)
curl -I https://senarathbank.com
curl -I https://www.senarathbank.com
curl -I https://ebanking.senarathbank.com
curl -I https://www.ebanking.senarathbank.com
```
Then in a browser:
- `https://senarathbank.com` → corporate site, padlock valid.
- `https://ebanking.senarathbank.com` → online banking; log in `customer01 / Welcome123!`.

HTTP automatically redirects to HTTPS. The "Log in / Open an account" links now
cross between the two domains correctly.

---

## 10. Auto-renew the certificate

Let's Encrypt certs last 90 days. Renew automatically, briefly bouncing the
gateway so certbot can use port 80:
```bash
sudo tee /etc/letsencrypt/renewal-hooks/pre/stop-gateway.sh >/dev/null <<'EOF'
#!/bin/sh
docker stop senarath-gateway
EOF
sudo tee /etc/letsencrypt/renewal-hooks/post/start-gateway.sh >/dev/null <<'EOF'
#!/bin/sh
docker start senarath-gateway
EOF
sudo chmod +x /etc/letsencrypt/renewal-hooks/pre/stop-gateway.sh /etc/letsencrypt/renewal-hooks/post/start-gateway.sh
sudo certbot renew --dry-run      # test it
```
(The certbot apt package already installs a twice-daily renew timer.)

---

## 9b (alternative). No public ports / ISP blocking → Cloudflare Tunnel

If you can't expose 80/443 (ISP block, no static IP), put the domain on
**Cloudflare** and use a **Cloudflare Tunnel** - no port-forwarding, no public IP:
1. Move `senarathbank.com` DNS to Cloudflare (change nameservers at your registrar).
2. Install `cloudflared` on WEB01, create a tunnel, and map hostnames to the local
   gateway:
   - `senarathbank.com`, `www` → `http://localhost:80` (corporate, via gateway)
   - `ebanking.senarathbank.com` → `http://localhost:80` (gateway routes by Host)
3. Cloudflare provides the public TLS; set SSL mode **Full**. In this mode you can
   run the gateway on **HTTP only** (drop the 443 block / cert) since Cloudflare
   terminates TLS at the edge. Tell me if you go this route and I'll give the
   exact gateway.conf + `cloudflared` config.

---

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| `senarath-gateway` restarting | Certificate missing → run §8.2, then `docker compose up -d`. |
| certbot "challenge failed" | DNS not propagated (recheck §3 `dig`), or port 80 not reachable from the internet (router forward / ISP block). |
| Site loads on IP but not the domain | DNS A records wrong or not propagated; `dig +short <name>` must return `PUBLIC_IP`. |
| "briefly unavailable" page | App can't reach SQL01 → fix `extra_hosts` IP (§6); SQL01 firewall allows 1433 from WEB01. |
| Port 80/443 "address already in use" | Something else on the host uses them (`sudo ss -tlnp | grep ':80\|:443'`) - stop it. |
| Browser cert warning | You reached it before issuance, or via a name not on the cert - cert covers exactly the 3 names in §8.2. |

### One-screen summary
```
OS     Ubuntu Server 24.04 LTS (native Docker)
DNS    A @,www,ebanking -> PUBLIC_IP ; router forward 80,443 -> WEB01
DOCKER install docker-ce ; git clone both repos ; sed extra_hosts SQL01 IP ; ufw 22,80,443
CERT   certbot certonly --standalone -d senarathbank.com -d www... -d ebanking...   (gateway DOWN)
UP     docker network create senarath-net ; (corporate) up ; (ebanking) up   -> gateway serves 80/443
TEST   https://senarathbank.com  +  https://ebanking.senarathbank.com -> customer01/Welcome123!
```
