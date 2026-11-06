# WEB01 on Windows Server 2025 - Docker setup & deploy

> **LAB ENVIRONMENT.** The web host is now **Windows Server 2025**. SQL01 is
> already built (full chain). The app containers are **Linux** images
> (`php:8.2-apache`, `nginx:alpine`, FreeTDS), so the `Dockerfile`s and
> `docker-compose.yml` files **do not change** - on Windows you run them as
> **Linux containers via WSL2**. This guide sets up WSL2 + Docker on the server,
> deploys corporate + ebanking + gateway, exposes 80/443, and connects to SQL01.

**Primary path = WSL2 + Docker Engine** (free, rock-solid for Linux containers).
Docker Desktop is given as an alternative in §9.

Fill in: `SQL01_IP` (your SQL server's address), `WEB01_IP` (this server's LAN IP),
repos `https://github.com/enigmuz/senarath-corporate` and
`https://github.com/enigmuz/senarath-ebanking`.

---

## 0. Prerequisites on the Windows Server 2025 box
- Administrator access.
- **Virtualization enabled.** WSL2 needs the *Virtual Machine Platform*. If this
  server is itself a VM, enable **nested virtualization** on its hypervisor first
  (Hyper-V host: `Set-VMProcessor <VMName> -ExposeVirtualizationExtensions $true`).
- Same LAN as SQL01 (so it can reach `SQL01:1433`).

---

## 1. Install WSL2 and an Ubuntu distro

Open **PowerShell as Administrator**:
```powershell
wsl --install -d Ubuntu
```
This enables WSL + Virtual Machine Platform and installs Ubuntu. **Reboot** if it
asks. After reboot, launch **Ubuntu** once from the Start menu and create a UNIX
username/password.

Make sure WSL is current (mirrored networking in §2 needs WSL ≥ 2.0):
```powershell
wsl --update
wsl --version        # confirm 'WSL version: 2.x'
wsl --set-default-version 2
```

---

## 2. Networking - make container ports reach the server's IP

Use **mirrored networking** so ports published by Docker inside WSL appear on the
Windows host's IP (and WSL can reach the LAN/SQL01 directly). Create the file
`C:\Users\<YourUser>\.wslconfig` (PowerShell):
```powershell
@"
[wsl2]
networkingMode=mirrored
"@ | Set-Content -Encoding ASCII "$env:USERPROFILE\.wslconfig"
```
Also enable **systemd** inside Ubuntu so Docker starts as a service. In the
**Ubuntu** shell:
```bash
sudo tee /etc/wsl.conf >/dev/null <<'EOF'
[boot]
systemd=true
EOF
```
Apply both (PowerShell):
```powershell
wsl --shutdown
```
Reopen **Ubuntu**. Verify systemd + mirrored:
```bash
systemctl is-system-running        # running (or degraded - both OK)
ip addr                            # in mirrored mode you'll see the host's IPs
```

---

## 3. Install Docker Engine inside Ubuntu (WSL2)

In the **Ubuntu** shell (same as a normal Ubuntu install):
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

# start + enable the daemon (systemd is on)
sudo systemctl enable --now docker
sudo usermod -aG docker $USER && newgrp docker
docker --version && docker compose version
docker run --rm hello-world        # sanity check
```

---

## 4. Get the code  (inside Ubuntu)
```bash
mkdir -p ~/senarath && cd ~/senarath
git clone https://github.com/enigmuz/senarath-corporate.git
git clone https://github.com/enigmuz/senarath-ebanking.git
```
(Private repos: `git clone https://<user>:<token>@github.com/enigmuz/senarath-ebanking.git`.)

---

## 5. Point the app at SQL01  (inside Ubuntu)

Keep `DB_HOST=SQL01.senarath.local` (as the guide specifies) and map it to your
SQL server's IP in the ebanking compose:
```bash
cd ~/senarath/senarath-ebanking
sed -i 's/SQL01.senarath.local:.*/SQL01.senarath.local:<SQL01_IP>"/' docker-compose.yml
grep -A2 extra_hosts docker-compose.yml
```

---

## 6. Build and start  (inside Ubuntu)
```bash
cd ~/senarath/senarath-corporate && docker compose up -d --build
cd ~/senarath/senarath-ebanking  && docker compose up -d --build
docker ps        # senarath-corporate, senarath-ebanking, senarath-gateway
```

---

## 7. Open the web ports on the Windows firewall

The gateway publishes **80** (and 443 after TLS). With mirrored networking those
land on the Windows host IP - allow them in **Windows Defender Firewall**
(PowerShell as Administrator):
```powershell
New-NetFirewallRule -DisplayName "Senarath Web 80"  -Direction Inbound -Protocol TCP -LocalPort 80  -Action Allow
New-NetFirewallRule -DisplayName "Senarath Web 443" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow
```
Do **not** open 1433 here - this box only serves the web; it *connects out* to SQL01.

---

## 8. Verify end to end

**Inside Ubuntu** (the app's real DB path):
```bash
docker exec -it senarath-ebanking bash -lc \
 '(echo > /dev/tcp/SQL01.senarath.local/1433) && echo "SQL01:1433 reachable" || echo "blocked"'
curl -s -o /dev/null -w "ebanking %{http_code}\n" -H 'Host: ebanking.senarathbank.com' http://127.0.0.1/index.php
```
**From another machine / the Windows host**: browse to `http://<WEB01_IP>/` with a
hosts entry for `ebanking.senarathbank.com` → `<WEB01_IP>`, then log in
`customer01 / Welcome123!`.

If ebanking shows "briefly unavailable", the container can't reach SQL01 → re-check
the `extra_hosts` IP (§5) and that SQL01's firewall allows 1433 from this server.

---

## 9. Alternative: Docker Desktop for Windows

If you prefer a GUI and it installs on your Server 2025 build:
1. Install **Docker Desktop for Windows**, choose the **WSL2 backend**, keep
   **Linux containers** (default).
2. `git clone` both repos (in PowerShell or WSL), set the `extra_hosts` IP (§5),
   then `docker compose up -d --build` in each.
3. Docker Desktop publishes container ports onto the Windows host automatically;
   still add the firewall rules in §7.

> Note: Docker Desktop needs a paid subscription for larger organisations (free
> for personal/education and small business). WSL2 + Docker Engine (above) has no
> such licence and is the cleaner choice for a server.

---

## 10. Start automatically after a reboot (optional, for a demo server)

With `restart: unless-stopped` on the containers and systemd running Docker, the
containers come back once WSL boots. Trigger WSL at startup with a scheduled task
(PowerShell as Administrator):
```powershell
$action  = New-ScheduledTaskAction -Execute "wsl.exe" -Argument "-d Ubuntu -u root -e sh -c 'service docker start; sleep 2; su - <youruser> -c \"cd ~/senarath/senarath-ebanking && docker compose up -d\"'"
$trigger = New-ScheduledTaskTrigger -AtStartup
Register-ScheduledTask -TaskName "SenarathLab-Up" -Action $action -Trigger $trigger -User "SYSTEM" -RunLevel Highest
```
(Replace `<youruser>`. Test it with `Start-ScheduledTask -TaskName SenarathLab-Up`.)

---

## 11. Publish to the public web route (unchanged)

Same as the lab design: `ebanking.senarathbank.com` / `www.senarathbank.com` →
**WAF01** → this WEB01 origin, and keep the **origin IP discoverable** (V2). TLS
can terminate at WAF01 and/or on this gateway (certbot inside Ubuntu + the
commented 443 blocks in `senarath-ebanking/nginx/gateway.conf`). SQL01 stays
internal. See `MASTER_RUNBOOK.md` §publish and `FULLCHAIN_BUILD_PLAN.md`.

---

## 12. Troubleshooting (Windows Server 2025 specifics)

| Symptom | Fix |
|---|---|
| `wsl --install` fails / no virtualization | Enable Virtual Machine Platform; if the server is a VM, enable **nested virtualization** on its hypervisor, then retry. |
| `docker` works in Ubuntu but site not reachable from LAN | Confirm `.wslconfig` has `networkingMode=mirrored`, run `wsl --shutdown`, reopen; add the §7 firewall rules. |
| Docker daemon not running after reboot | `sudo systemctl enable --now docker`; confirm `/etc/wsl.conf` has `systemd=true`; use the §10 task. |
| Container can't reach SQL01 | `extra_hosts` IP correct (§5); SQL01 firewall allows 1433 from `<WEB01_IP>`; `docker compose build --no-cache ebanking`. |
| Ports 80/443 already used on the server (IIS) | Stop/disable **IIS (W3SVC)**: `Stop-Service W3SVC; Set-Service W3SVC -StartupType Disabled`. |
| Mirrored mode unavailable | Update WSL (`wsl --update`); if still unsupported, use `netsh interface portproxy add v4tov4 listenport=80 connectport=80 connectaddress=<wsl-ip>` (less ideal - WSL IP can change). |

### One-screen summary
```
WSL2     wsl --install -d Ubuntu ; wsl --update ; set-default-version 2
NET      .wslconfig => networkingMode=mirrored ; /etc/wsl.conf => systemd=true ; wsl --shutdown
DOCKER   (in Ubuntu) install docker-ce ; systemctl enable --now docker
CODE     git clone both repos ; sed extra_hosts "SQL01.senarath.local:<SQL01_IP>"
UP       docker compose up -d --build  (corporate, then ebanking)
FIREWALL (Windows) allow inbound 80,443   (NOT 1433)
TEST     docker exec ... /dev/tcp/SQL01.senarath.local/1433 ; browse ebanking.senarathbank.com -> customer01/Welcome123!
```
