# Senarath Bank Lab - Deploy on a single Ubuntu server (Docker)

> **LAB ENVIRONMENT.** Fictional bank, synthetic data, intentional weaknesses
> (V1-V6). This guide deploys the whole web tier on **one Ubuntu server** with
> Docker, and runs **MSSQL as a container on the same box but kept internal**
> (never published to the internet). The two web sites are reachable publicly
> through the nginx gateway; MSSQL is reachable only on the private Docker
> network. That satisfies "web is public in the DMZ, the database sits internal."
>
> If you instead need the full Windows / Active Directory pivot (D1-D6:
> xp_cmdshell -> SYSTEM -> domain), MSSQL must live on a **separate Windows
> SQL01** host - use `DEPLOYMENT_GUIDE.md` (§2) for that. For the app to work
> end to end and to demo V1-V6 on the web tier, MSSQL-in-Docker below is enough.

```
          Internet / event
                │  :80 (:443)
                ▼
   ┌─────────────────────────────────────────────┐
   │  Ubuntu server  (Docker + docker compose)    │
   │                                              │
   │   nginx gateway ──► corporate (www)          │
   │                └──► ebanking (online)        │
   │                         │ 1433 (private)     │
   │                         ▼                     │
   │                   sql01  (MSSQL 2022)         │  ← NOT published
   │                   docker network: senarath-net│
   └─────────────────────────────────────────────┘
```

---

## 0. Before you start

- An Ubuntu server (22.04 or 24.04) you can SSH into, with sudo.
- Open **only** ports **22** (SSH), **80**, and **443** to the internet
  (cloud security group / `ufw`). **Do not** open 1433.
- The two project folders on the server: `senarath-corporate` and
  `senarath-ebanking` (clone them from your repos, or copy them up).

Pick two secrets now and keep them safe (used below):
- `SA_PASSWORD` - the MSSQL **sa** admin password, e.g. `Str0ng!Sa#2026`
  (must be 8+ chars with upper/lower/digit/symbol).
- The app login stays the documented lab value **`lab_app` / `DbLab123!`**
  (created by the schema script; it's weakness V6).

---

## 1. Install Docker

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

---

## 2. Get the code onto the server

```bash
mkdir -p ~/senarath && cd ~/senarath
git clone <your-corporate-repo-url> senarath-corporate
git clone <your-ebanking-repo-url>  senarath-ebanking
# (to update later: cd into each and `git pull`)
```

---

## 3. Create the private network and start MSSQL (internal)

```bash
# one shared network for everything
docker network create senarath-net

# choose your sa password once in this shell
export SA_PASSWORD='Str0ng!Sa#2026'

# MSSQL 2022, attached to the private network, NOT published to the host.
docker run -d --name sql01 --restart unless-stopped \
  --network senarath-net \
  -e "ACCEPT_EULA=Y" \
  -e "MSSQL_SA_PASSWORD=$SA_PASSWORD" \
  -e "MSSQL_PID=Developer" \
  -v sql01data:/var/opt/mssql \
  mcr.microsoft.com/mssql/server:2022-latest
```

> Because there is no `-p` flag, port 1433 is reachable **only** from other
> containers on `senarath-net` (that's the ebanking app, and - for the demo -
> the attacker's foothold inside it after V5/V6). It is **not** exposed to the
> host or the internet.
>
> If you want to administer it from the server itself with Azure Data Studio,
> add `-p 127.0.0.1:1433:1433` so it binds to localhost only - never `0.0.0.0`.

Wait until the engine is ready:

```bash
until docker run --rm --network senarath-net mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S sql01 -U sa -P "$SA_PASSWORD" -Q "SELECT 1" >/dev/null 2>&1; do
  echo "waiting for MSSQL to start..."; sleep 3
done
echo "MSSQL is up."
```

---

## 4. Create the database, tables, login and 200+ customers

Run the two SQL scripts from `senarath-ebanking/db/` against the container.
We use the `mssql-tools` image (its `sqlcmd` connects without TLS fuss).

```bash
cd ~/senarath/senarath-ebanking

# 4a. schema + lab_app login
docker run --rm --network senarath-net -v "$PWD/db:/db:ro" \
  mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S sql01 -U sa -P "$SA_PASSWORD" -i /db/01_schema.sql

# 4b. seed 205 synthetic customers, accounts, ~3,400 transactions
docker run --rm --network senarath-net -v "$PWD/db:/db:ro" \
  mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S sql01 -U sa -P "$SA_PASSWORD" -d SenarathLab -i /db/02_seed_customers.sql
```

Expected tail:
```
Seed complete: 205 customers, 406 accounts, 3445 transactions, 122 beneficiaries.
```

Verify the app login works:
```bash
docker run --rm --network senarath-net mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S sql01 -U lab_app -P "DbLab123!" -d SenarathLab \
  -Q "SELECT COUNT(*) AS customers FROM customers;"
# -> customers = 205
```

---

## 5. Point the app at the MSSQL container

Edit `senarath-ebanking/.env` so `DB_HOST` is the container name `sql01`:

```bash
cd ~/senarath/senarath-ebanking
sed -i 's/^DB_HOST=.*/DB_HOST=sql01/' .env
grep -E 'DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASSWORD' .env
```
You should see:
```
DB_HOST=sql01
DB_PORT=1433
DB_NAME=SenarathLab
DB_USER=lab_app
DB_PASSWORD=DbLab123!
```

> The `extra_hosts` line in `docker-compose.yml` was for the separate-Windows-host
> setup; with MSSQL as the `sql01` container it is simply unused - you can leave
> it or delete it.

---

## 6. Bring up the two sites + the gateway

```bash
# corporate site
cd ~/senarath/senarath-corporate
docker compose up -d --build

# banking app + nginx gateway (publishes port 80)
cd ~/senarath/senarath-ebanking
docker compose up -d --build

docker ps
```
You should see four containers: `sql01`, `senarath-corporate`,
`senarath-ebanking`, `senarath-gateway`.

Local smoke test on the server:
```bash
curl -s -o /dev/null -w "corporate %{http_code}\n" -H 'Host: www.senarathbank.com'    http://127.0.0.1/
curl -s -o /dev/null -w "ebanking  %{http_code}\n" -H 'Host: online.senarathbank.com' http://127.0.0.1/index.php
```
Both should return `200`. If ebanking returns the "briefly unavailable" page,
the app can't reach MSSQL - see §9.

---

## 7. Host names

The gateway routes by the `Host` header:
`www.senarathbank.com`/`senarathbank.com` -> corporate,
`online.senarathbank.com` -> ebanking.

- **Public demo:** create DNS **A records** for `www` and `online` pointing at
  the server's public IP (or point them at your WAF, with the server as origin).
- **Quick test without DNS:** on your laptop add to the hosts file
  (`C:\Windows\System32\drivers\etc\hosts` or `/etc/hosts`):
  ```
  <SERVER_PUBLIC_IP>  www.senarathbank.com
  <SERVER_PUBLIC_IP>  online.senarathbank.com
  ```
Then open `http://online.senarathbank.com` and log in with
`customer01 / Welcome123!`.

---

## 8. TLS (recommended for a public demo)

```bash
sudo apt-get install -y certbot
sudo certbot certonly --standalone -d online.senarathbank.com -d www.senarathbank.com
```
Then in `senarath-ebanking/docker-compose.yml` publish 443 and mount the certs
into the gateway, and enable the `443` server blocks in
`senarath-ebanking/nginx/gateway.conf` (they're there, commented, pointing at
`/etc/nginx/certs/...`). Example gateway edit:
```yaml
  gateway:
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/gateway.conf:/etc/nginx/conf.d/default.conf:ro
      - /etc/letsencrypt:/etc/nginx/certs:ro
```
`docker compose up -d` to apply.

---

## 9. If the app can't reach MSSQL

```bash
# Is sql01 running?
docker ps --filter name=sql01

# Can the ebanking container resolve + reach it on 1433?
docker exec -it senarath-ebanking bash -lc \
  'getent hosts sql01; (echo > /dev/tcp/sql01/1433) && echo "1433 reachable" || echo "1433 blocked"'

# App + gateway logs
docker logs --tail 50 senarath-ebanking
docker logs --tail 30 senarath-gateway

# MSSQL logs
docker logs --tail 40 sql01
```
Checklist: `sql01` container up; both app and sql01 on `senarath-net`; `.env`
has `DB_HOST=sql01`; schema + seed ran without errors; you rebuilt the app after
editing `.env` (`docker compose up -d --build`). If login works via the
`mssql-tools` check in §4 but the app still fails, rebuild the ebanking image
(`docker compose build --no-cache ebanking`) so the FreeTDS driver is present.

---

## 10. Everyday commands

```bash
# restart a piece
docker restart senarath-ebanking

# re-seed the DB (clears test data + uploaded V5 files' DB rows)
cd ~/senarath/senarath-ebanking
docker run --rm --network senarath-net -v "$PWD/db:/db:ro" \
  mcr.microsoft.com/mssql-tools /opt/mssql-tools/bin/sqlcmd \
  -S sql01 -U sa -P "$SA_PASSWORD" -d SenarathLab -i /db/02_seed_customers.sql

# clear uploaded lab artifacts (V5)
docker exec senarath-ebanking sh -lc 'rm -f /var/www/html/uploads/*.php'

# stop everything for the day
cd ~/senarath/senarath-ebanking && docker compose down
cd ~/senarath/senarath-corporate && docker compose down
docker stop sql01            # data persists in the sql01data volume
```

---

## 11. Safety for a public, deliberately-vulnerable site

- Expose **only 80/443**. Keep **1433 unpublished** (internal to Docker).
- Bring the server online **only during the demo window**; `docker compose down`
  and `docker stop sql01` afterwards.
- Restrict inbound 80/443 to the event's source ranges if you can.
- Never reuse these lab passwords anywhere real.
- The sites carry a small "(fictional / lab)" footer note - keep it so the site
  can't be mistaken for, or reused as, a real bank.
```

### One-screen quick start
```bash
# 1 Docker (see §1)                      2 code
cd ~/senarath && git clone <corp> senarath-corporate && git clone <eb> senarath-ebanking
# 3 network + MSSQL (internal)
docker network create senarath-net
export SA_PASSWORD='Str0ng!Sa#2026'
docker run -d --name sql01 --restart unless-stopped --network senarath-net \
  -e ACCEPT_EULA=Y -e MSSQL_SA_PASSWORD="$SA_PASSWORD" -e MSSQL_PID=Developer \
  -v sql01data:/var/opt/mssql mcr.microsoft.com/mssql/server:2022-latest
# 4 init DB (after it starts)
cd ~/senarath/senarath-ebanking
docker run --rm --network senarath-net -v "$PWD/db:/db:ro" mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S sql01 -U sa -P "$SA_PASSWORD" -i /db/01_schema.sql
docker run --rm --network senarath-net -v "$PWD/db:/db:ro" mcr.microsoft.com/mssql-tools \
  /opt/mssql-tools/bin/sqlcmd -S sql01 -U sa -P "$SA_PASSWORD" -d SenarathLab -i /db/02_seed_customers.sql
# 5 point app at sql01
sed -i 's/^DB_HOST=.*/DB_HOST=sql01/' .env
# 6 up
cd ~/senarath/senarath-corporate && docker compose up -d --build
cd ~/senarath/senarath-ebanking  && docker compose up -d --build
```
