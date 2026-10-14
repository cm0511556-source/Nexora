#!/usr/bin/env bash
# Smoke test: every admin + public page returns a sane status with no PHP errors.
set -u
cd /workspace/quiet-curie

BASE=http://127.0.0.1:8024
DB=database/database.sqlite
BACKUP=/tmp/nx-db-smoke-backup.sqlite
SRV=""
PASS=0; FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

cp "$DB" "$BACKUP" || { echo "cannot back up DB"; exit 1; }
restore(){ [ -n "$SRV" ] && kill "$SRV" 2>/dev/null; cp "$BACKUP" "$DB" 2>/dev/null; }
trap restore EXIT

php -S 127.0.0.1:8024 -t public > /tmp/smoke-server.log 2>&1 &
SRV=$!
sleep 1.5

csrf(){ php -r '$h=file_get_contents($argv[1]); echo preg_match("/name=\"csrf_token\"\s+value=\"([^\"]+)\"/",$h,$m)?$m[1]:"";' "$1"; }

curl -s -c /tmp/nx-smoke.jar "$BASE/admin/login.php" -o /tmp/nx-smoke-login.html
TOK=$(csrf /tmp/nx-smoke-login.html)
curl -s -b /tmp/nx-smoke.jar -c /tmp/nx-smoke.jar -o /dev/null \
  --data-urlencode "csrf_token=$TOK" --data-urlencode "email=admins@logistics.com" \
  --data-urlencode "password=Admins@Logistics0" "$BASE/admin/login.php"

hit(){
  local url="$1" want="$2" label="$3"
  local c
  c=$(curl -s --no-keepalive -H 'Connection: close' -b /tmp/nx-smoke.jar -o /tmp/nx-smoke-page.html -w '%{http_code}' "$BASE/$url")
  if [ "$c" = "$want" ]; then ok "$label -> $c"; else no "$label -> $c (want $want)"; fi
  if [ -s /tmp/nx-smoke-page.html ] && grep -qi "Fatal error\|Parse error\|Uncaught" /tmp/nx-smoke-page.html; then
    no "$label rendered a PHP error message"
  fi
}

echo "== Public pages =="
hit ""                 "200" "/ (home)"
hit "index.php"        "200" "/index.php"
hit "pre-track.php"    "200" "/pre-track.php"
hit "track.php"        "200" "/track.php (shell)"
hit "manifest.json" "200" "/manifest.json"
hit "service-worker.js"    "200" "/service-worker.js"

echo "== Admin pages (GET) =="
for p in index.php shipments-hub.php shipments.php shipment-management.php \
         tracking-operations.php fleet.php carriers.php testimonials.php \
         site-content.php content.php settings.php people.php \
         customer-messages.php tracking-lookups.php manual.php customers.php \
         proof-of-delivery.php audit-log.php; do
  hit "admin/$p" "200" "admin/$p"
done

echo "== JSON endpoints that need ?shipment_id= =="
hit "admin/hold-management.php?shipment_id=2" "200" "admin/hold-management.php?shipment_id=2"

echo "== Admin pages needing ?id= =="
hit "admin/shipment-control.php?id=2" "200" "admin/shipment-control.php?id=2"
hit "admin/shipment-route.php?id=2"   "200" "admin/shipment-route.php?id=2"

echo "== Expected-auth-redirect / API pages =="
hit "track-api.php" "400" "track-api.php (no id)"

echo "== PHP errors in server log =="
if grep -Ei 'PHP (Warning|Fatal|Parse|Notice|Deprecated)' /tmp/smoke-server.log > /tmp/nx-smoke-err.txt; then
  no "server log has PHP errors:"; sed 's/^/    /' /tmp/nx-smoke-err.txt | head -20
else
  ok "server log clean"
fi

restore; SRV=""
echo
echo "==== SMOKE: $PASS passed, $FAIL failed ===="
exit $FAIL
