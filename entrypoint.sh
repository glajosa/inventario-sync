#!/bin/bash
set -e

# El cron NO hereda las env vars del contenedor. Las volcamos a un archivo
# que el job de cron carga antes de correr rebuild.php.
cat > /data/env.sh <<EOF
export DATA_DIR="/data"
export BITRIX_WEBHOOK="${BITRIX_WEBHOOK}"
export OUTBOUND_TOKEN="${OUTBOUND_TOKEN}"
EOF
chmod 600 /data/env.sh

# arrancar cron en background
cron

# rebuild inicial al arrancar (para no esperar 30 min a tener allowlist)
( sleep 5; DATA_DIR=/data BITRIX_WEBHOOK="${BITRIX_WEBHOOK}" OUTBOUND_TOKEN="${OUTBOUND_TOKEN}" \
    php /var/www/html/rebuild.php >> /data/cron.log 2>&1 ) &

exec "$@"
