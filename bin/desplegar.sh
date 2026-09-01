#!/usr/bin/env bash
# Despliegue de inventario-sync que SÍ dice si funcionó.
#
# 🔴 EL PROBLEMA. `services.app.deployService` de EasyPanel devuelve HTTP 000: la
# conexión se corta mientras compila. La respuesta no dice nada. El 31-ago-2026 el
# despliegue corrió y la llamada igual dio 000 — creerle habría llevado a
# dispararlo otra vez, y dos disparos dejan el servicio inconsistente ("Invariant
# failed" en noral-historias, hubo que recrearlo).
#
# EL ARREGLO no es que la llamada conteste bien. Es dejar de preguntarle a la
# llamada: se dispara UNA vez y después se le pregunta a la app qué código está
# sirviendo, comparando la huella (md5 de cada archivo). Ver huella.php.
#
# Además comprueba ANTES de subir si producción está sirviendo algo que vos no
# tenés — la regla de CLAUDE.md para las sesiones en paralelo.
#
# Uso:  bin/desplegar.sh            despliega y espera
#       bin/desplegar.sh --revisar  solo compara, no despliega
set -uo pipefail

PROYECTO=galjosa
SERVICIO=inventario-sync
URL_APP=https://galjosa-inventario-sync.pwluu1.easypanel.host
ESPERA_MAX=600      # 10 min: el build tarda ~2-4
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

rojo()  { printf '\033[31m%s\033[0m\n' "$*"; }
verde() { printf '\033[32m%s\033[0m\n' "$*"; }
gris()  { printf '\033[90m%s\033[0m\n' "$*"; }

set -a; . ~/.galjosa-secure/easypanel-api.env; set +a
: "${EASYPANEL_URL:?falta EASYPANEL_URL}" "${EASYPANEL_TOKEN:?falta EASYPANEL_TOKEN}"

# El OUTBOUND_TOKEN se saca del propio servicio y NUNCA se imprime.
TOK="$(curl -sk -X POST "$EASYPANEL_URL/api/trpc/services.app.inspectService" \
  -H "Authorization: Bearer $EASYPANEL_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"json\":{\"projectName\":\"$PROYECTO\",\"serviceName\":\"$SERVICIO\"}}" --max-time 30 \
  | python3 -c "import json,sys,re; e=json.load(sys.stdin)['json'].get('env',''); m=re.search(r'^OUTBOUND_TOKEN=(.+)\$',e,re.M); print(m.group(1).strip() if m else '')")"
[ -n "$TOK" ] || { rojo "No se pudo leer OUTBOUND_TOKEN del servicio."; exit 1; }

huella_local() { php "$REPO/huella.php" "$REPO" | python3 -c "import json,sys;print(json.load(sys.stdin)['total'])"; }
huella_prod()  { curl -s --max-time 25 "$URL_APP/?huella=1&token=$TOK" \
                 | python3 -c "import json,sys
try: print(json.load(sys.stdin)['total'])
except Exception: print('')" 2>/dev/null; }

LOCAL="$(huella_local)"
ANTES="$(huella_prod)"
gris "huella local      $LOCAL"
gris "huella producción ${ANTES:-(no responde / versión sin el endpoint)}"

if [ "$ANTES" = "$LOCAL" ]; then
  verde "Producción ya está sirviendo exactamente este árbol. No hay nada que desplegar."
  exit 0
fi

# ¿el repo está limpio y subido?
if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
  rojo "Hay cambios sin commitear. EasyPanel despliega la RAMA de GitHub, no tu disco."
  git -C "$REPO" status --short
  exit 1
fi
if [ "$(git -C "$REPO" rev-parse HEAD)" != "$(git -C "$REPO" rev-parse '@{u}' 2>/dev/null)" ]; then
  rojo "Tu HEAD no está en origin. Hacé push antes de desplegar."
  exit 1
fi

if [ "${1:-}" = "--revisar" ]; then
  gris "Solo revisión: no se disparó nada."
  exit 0
fi

# ---- UN SOLO DISPARO. El 000 es esperado y NO significa que falló. ----
echo "Disparando el despliegue (una sola vez)…"
CODIGO="$(curl -sk --max-time 120 -o /dev/null -w '%{http_code}' -X POST \
  "$EASYPANEL_URL/api/trpc/services.app.deployService" \
  -H "Authorization: Bearer $EASYPANEL_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"json\":{\"projectName\":\"$PROYECTO\",\"serviceName\":\"$SERVICIO\"}}")"
gris "respuesta del disparo: HTTP $CODIGO  (000 es normal: la conexión se corta mientras compila)"

echo "Esperando a que el código nuevo esté sirviendo…"
INICIO=$SECONDS
while [ $((SECONDS - INICIO)) -lt $ESPERA_MAX ]; do
  sleep 10
  AHORA="$(huella_prod)"
  if [ "$AHORA" = "$LOCAL" ]; then
    verde "DESPLEGADO y verificado a los $((SECONDS - INICIO))s."
    verde "Producción sirve la huella $LOCAL — idéntica a tu árbol."
    exit 0
  fi
  printf '\r  %3ds · producción en %s ' "$((SECONDS - INICIO))" "${AHORA:0:12}"
done

echo
rojo "Pasaron $ESPERA_MAX s y producción sigue sin coincidir."
rojo "NO vuelvas a disparar el despliegue sin mirar esto primero: un segundo disparo"
rojo "deja el servicio inconsistente. Archivos que bailan:"
diff <(php "$REPO/huella.php" "$REPO" | python3 -c "
import json,sys
for k,v in json.load(sys.stdin)['archivos'].items(): print(v,k)") \
     <(curl -s --max-time 30 "$URL_APP/?huella=detalle&token=$TOK" | python3 -c "
import json,sys
for k,v in json.load(sys.stdin).get('archivos',{}).items(): print(v,k)") | head -30
exit 1
