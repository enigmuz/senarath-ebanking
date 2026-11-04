# ============================================================================
# Senarath Bank — Online Banking (origin application)
# LAB ENVIRONMENT · fictional institution · intentional weaknesses V1–V6
# PHP 8.2 + Apache, connecting to MSSQL on SQL01 via FreeTDS (PDO dblib).
# ============================================================================
FROM php:8.2-apache

# --- MSSQL connectivity via FreeTDS / PDO dblib ---------------------------
# (FreeTDS avoids the Microsoft ODBC repo and works fine against MSSQL 2019/2022.)
RUN apt-get update && apt-get install -y --no-install-recommends \
        freetds-dev freetds-bin unixodbc-dev \
    && docker-php-ext-configure pdo_dblib --with-libdir=lib/x86_64-linux-gnu \
    && docker-php-ext-install pdo_dblib \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# --- PHP settings (lab) ---------------------------------------------------
# display_errors ON so the environment is easy to walk during the demo.
# Upload limits sized for KYC documents. NOTE: no upload hardening here — V5.
RUN { \
      echo 'display_errors=On'; \
      echo 'file_uploads=On'; \
      echo 'upload_max_filesize=8M'; \
      echo 'post_max_size=10M'; \
      echo 'expose_php=On'; \
    } > /usr/local/etc/php/conf.d/zz-ebanking.ini

# Silence the Apache FQDN warning.
RUN echo 'ServerName online.senarathbank.com' >> /etc/apache2/apache2.conf

# --- Application code ------------------------------------------------------
COPY src/ /var/www/html/

# The uploads directory is inside the webroot and is intentionally left
# writable AND PHP-executable (V5). Do not add a .htaccess disabling PHP here.
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 0775 /var/www/html/uploads

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=4s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/index.php")?0:1);' || exit 1
