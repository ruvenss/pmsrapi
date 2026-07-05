# ─────────────────────────────────────────────────────────────────────────────
#  PMSRAPI v2 — DEV / TEST image.  ⚠ NEVER SHIP THIS TO PRODUCTION.
#  It exists only to run the v2 test suite against real MySQL + Redis.
#  How production is built/deployed is intentionally out of scope here.
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.3-cli

# v2 needs: mysqli (prepared statements), redis (phpredis), curl + json (bundled).
RUN docker-php-ext-install mysqli \
 && yes '' | pecl install redis \
 && docker-php-ext-enable redis \
 && php -m | grep -E 'mysqli|redis'

COPY test-env/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# The repo is bind-mounted here at run time (see docker-compose.yml).
WORKDIR /opt/pmsrapi

EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
CMD ["serve"]
