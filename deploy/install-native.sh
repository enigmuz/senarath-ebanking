#!/usr/bin/env bash
# ============================================================================
# Senarath Bank - native (no Docker) WEB01 installer   LAB ENVIRONMENT
# ----------------------------------------------------------------------------
# Installs Apache + PHP 8.2 + FreeTDS/pdo_dblib, wires the two sites as
# name-based vhosts, sets the SQL01 host mapping, and fixes permissions.
# TLS is issued separately with `certbot --apache` once DNS is live
# (see DEPLOY_UBUNTU_NATIVE.md section 7).
#
# PREREQUISITE: clone BOTH repos first, into /var/www:
#   sudo git clone https://github.com/enigmuz/senarath-corporate.git /var/www/senarath-corporate
#   sudo git clone https://github.com/enigmuz/senarath-ebanking.git  /var/www/senarath-ebanking
#
# USAGE (run on WEB01):
#   sudo SQL01_IP=10.10.20.11 bash /var/www/senarath-ebanking/deploy/install-native.sh
# ============================================================================
set -euo pipefail

CORP_DIR=/var/www/senarath-corporate
EBANK_DIR=/var/www/senarath-ebanking
SQL01_HOST="SQL01.senarath.local"
SQL01_IP="${SQL01_IP:-}"

echo "[*] Senarath native installer (WEB01, no Docker)"

if [[ $EUID -ne 0 ]]; then echo "Run with sudo/root."; exit 1; fi
for d in "$CORP_DIR" "$EBANK_DIR"; do
  [[ -d "$d/src" ]] || { echo "ERROR: $d/src not found - clone the repos into /var/www first (see header)."; exit 1; }
done

echo "[*] 1/6 Installing PHP 8.2 + Apache + FreeTDS (ondrej PPA)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl git
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
  apache2 libapache2-mod-php8.2 \
  php8.2-cli php8.2-sybase php8.2-mbstring php8.2-xml \
  freetds-bin freetds-common
# pdo_dblib ships in php8.2-sybase; FreeTDS provides the MSSQL transport.

echo "[*] 2/6 Enabling Apache modules + making PHP 8.2 the Apache SAPI"
a2enmod rewrite headers >/dev/null 2>&1 || true
a2dismod mpm_event >/dev/null 2>&1 || true   # mod_php needs prefork
a2enmod mpm_prefork php8.2 >/dev/null 2>&1 || true

echo "[*] 3/6 FreeTDS: force TDS 7.4 so MSSQL 2019/2022 logins succeed"
if ! grep -q "tds version = 7.4" /etc/freetds/freetds.conf 2>/dev/null; then
  sed -i '/^\[global\]/a \\ttds version = 7.4' /etc/freetds/freetds.conf || true
fi

echo "[*] 4/6 Installing vhosts + PHP ini"
install -m 0644 "$EBANK_DIR/deploy/apache/senarath.conf" /etc/apache2/sites-available/senarath.conf
install -m 0644 "$EBANK_DIR/deploy/php/zz-ebanking.ini"   /etc/php/8.2/apache2/conf.d/zz-ebanking.ini
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite senarath >/dev/null 2>&1 || true

echo "[*] 5/6 SQL01 host mapping + permissions"
if [[ -n "$SQL01_IP" ]]; then
  if grep -q "$SQL01_HOST" /etc/hosts; then
    sed -i "s/^.*[[:space:]]$SQL01_HOST.*$/$SQL01_IP   $SQL01_HOST/" /etc/hosts
  else
    echo "$SQL01_IP   $SQL01_HOST" >> /etc/hosts
  fi
  echo "    /etc/hosts: $SQL01_IP -> $SQL01_HOST"
else
  echo "    (!) SQL01_IP not supplied - add '<ip>  $SQL01_HOST' to /etc/hosts yourself,"
  echo "        or set DB_HOST to the IP in $EBANK_DIR/.env"
fi
# uploads dir must be writable by the web user and stay PHP-executable (V5)
mkdir -p "$EBANK_DIR/src/uploads"
chown -R www-data:www-data "$EBANK_DIR/src/uploads"
chmod -R 0775 "$EBANK_DIR/src/uploads"
# .env (V6) stays ABOVE the webroot; web user must be able to read it
chown www-data:www-data "$EBANK_DIR/.env" 2>/dev/null || true
chmod 0640 "$EBANK_DIR/.env" 2>/dev/null || true

echo "[*] 6/6 Validating config + reloading Apache"
apache2ctl configtest
systemctl enable --now apache2
systemctl reload apache2

echo
echo "[+] Done. Local smoke test:"
echo "    curl -s -o /dev/null -w 'corp %{http_code}\\n' -H 'Host: senarathbank.com' http://127.0.0.1/"
echo "    curl -s -o /dev/null -w 'bank %{http_code}\\n' -H 'Host: ebanking.senarathbank.com' http://127.0.0.1/index.php"
echo "[+] Next: issue TLS ->  sudo certbot --apache -d senarathbank.com -d www.senarathbank.com -d ebanking.senarathbank.com -d www.ebanking.senarathbank.com"
