#!/bin/bash
set -e

# El cron NO hereda las env vars del contenedor. Las volcamos a un archivo
# que los jobs de cron cargan antes de correr.
cat > /data/env.sh <<EOF
export DATA_DIR="/data"
export BITRIX_WEBHOOK="${BITRIX_WEBHOOK}"
export OUTBOUND_TOKEN="${OUTBOUND_TOKEN}"
EOF
chmod 600 /data/env.sh

# arrancar cron en background
cron

# AL ARRANCAR (cubre el hueco del deploy/reinicio, sin esperar los crons):
#   1. rebuild  -> allowlist lista de una (no esperar 6h)
#   2. reconcile -> recupera CUALQUIER evento perdido durante el downtime del deploy
# Secuencial y en background para no bloquear apache. Espera 5s a que el
# webhook entrante esté resoluble.
(
  sleep 5
  php /var/www/html/rebuild.php   >> /data/cron.log 2>&1
  php /var/www/html/reconcile.php >> /data/cron.log 2>&1
) &

exec "$@"
