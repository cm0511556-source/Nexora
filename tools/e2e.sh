#!/usr/bin/env bash
# End-to-end behavioural audit: admin creates a shipment -> customer sees it.
# Runs against a scratch copy of the real DB and always restores it.
set -u
cd /workspace/quiet-curie

BASE=http://127.0.0.1:8022
DB=database/database.sqlite
BACKUP=/tmp/nx-db-e2e-backup.sqlite
SRV=""
PORT=8022

cp "$DB" "$BACKUP" || { echo "cannot back up DB"; exit 1; }
restore() {
  [ -n "$SRV" ] && kill "$SRV" 2>/dev/null
  cp "$BACKUP" "$DB" 2>/dev/null
}
trap restore EXIT

php -S 127.0.0.1:$PORT -t public > /tmp/e2e-server.log 2>&1 &
SRV=$!
sleep 1.5

PASS=0; FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
eq(){ if [ "$2" = "$3" ]; then ok "$1 = $2"; else no "$1 (got '$2', want '$3')"; fi; }

# Runs a scalar query. Raw output (no var_export quoting).
q(){ php -r '$d=new PDO("sqlite:database/database.sqlite");$s=$d->prepare($argv[1]);$s->execute(array_slice($argv,2));$v=$s->fetchColumn();if($v===false)echo "FALSE";elseif($v===null)echo "NULL";else echo $v;' "$@" 2>/dev/null; }
# Numeric equality (tolerant of 600 vs 600.0).
num(){ php -r 'echo (abs((float)$argv[1]-(float)$argv[2])<0.0001)?"1":"0";' "$1" "$2"; }

# CSRF hidden field can span multiple lines in some forms.
csrf(){ php -r '$h=file_get_contents($argv[1]); echo preg_match("/name=\"csrf_token\"\s+value=\"([^\"]+)\"/",$h,$m)?$m[1]:"";' "$1"; }
# Some pages (e.g. shipment-control) emit the token as a JS variable instead.
csrf_js(){ php -r '$h=file_get_contents($argv[1]); echo preg_match("/csrfToken\s*=\s*\"([^\"]+)\"/",$h,$m)?$m[1]:"";' "$1"; }

echo "== 1. Admin login =="
curl -s -c /tmp/e2e-admin.jar "$BASE/admin/login.php" -o /tmp/e2e-login.html
TOK=$(csrf /tmp/e2e-login.html)
CODE=$(curl -s -b /tmp/e2e-admin.jar -c /tmp/e2e-admin.jar -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=$TOK" --data-urlencode "email=admins@logistics.com" \
  --data-urlencode "password=Admins@Logistics0" "$BASE/admin/login.php")
eq "admin login HTTP (expect 302 = success)" "$CODE" "302"
if [ "$CODE" != "302" ]; then echo "!! login failed, aborting"; exit 1; fi

echo "== 2. Create shipment through the admin form =="
curl -s -b /tmp/e2e-admin.jar -c /tmp/e2e-admin.jar "$BASE/admin/shipments.php" -o /tmp/e2e-ship.html
TOK=$(csrf /tmp/e2e-ship.html)
TS=$(date +%s)
TRK="TEST-E2E-$TS"
EMAIL="e2e$TS@example.com"
PHONE="+2348000$TS"
curl -s -b /tmp/e2e-admin.jar -c /tmp/e2e-admin.jar -o /tmp/e2e-create.html -w '%{http_code}' \
  --data-urlencode "csrf_token=$TOK" \
  --data-urlencode "tracking_number=$TRK" \
  --data-urlencode "transport_type=vehicle" \
  --data-urlencode "customer_name=E2E Tester" \
  --data-urlencode "customer_email=$EMAIL" \
  --data-urlencode "customer_phone=$PHONE" \
  --data-urlencode "vehicle_name=Test Van" \
  --data-urlencode "vehicle_number=TX-1" \
  --data-urlencode "status=in_transit" \
  --data-urlencode "origin=Lagos" \
  --data-urlencode "destination=Abuja" \
  --data-urlencode "origin_lat=6.5244" \
  --data-urlencode "origin_lng=3.3792" \
  --data-urlencode "destination_lat=9.0765" \
  --data-urlencode "destination_lng=7.3986" \
  --data-urlencode "speed=600" \
  --data-urlencode "estimated_delivery=2026-01-01T12:00" \
  --data-urlencode "package_description=E2E audit package" \
  "$BASE/admin/shipments.php" >/dev/null

SID=$(q "SELECT id FROM shipments WHERE tracking_number=?" "$TRK")
if [ -n "$SID" ] && [ "$SID" != "FALSE" ]; then ok "shipment row created (id=$SID)"; else no "shipment row NOT created"; fi
eq "shipment status"          "$(q 'SELECT status FROM shipments WHERE id=?' "$SID")" "in_transit"
[ "$(num "$(q 'SELECT speed FROM shipments WHERE id=?' "$SID")" 600)" = "1" ] && ok "shipment speed = 600" || no "shipment speed wrong"
CID=$(q "SELECT customer_id FROM shipments WHERE id=?" "$SID")
if [ -n "$CID" ] && [ "$CID" != "NULL" ] && [ "$CID" != "FALSE" ]; then ok "linked to customers row (id=$CID)"; else no "customer_id not linked"; fi
eq "customer auto-created"   "$(q 'SELECT COUNT(*) FROM customers WHERE email=?' "$EMAIL")" "1"
eq "tracking_controls seeded" "$(q 'SELECT COUNT(*) FROM tracking_controls WHERE shipment_id=?' "$SID")" "1"
eq "creation event logged"   "$(q 'SELECT COUNT(*) FROM tracking_events WHERE shipment_id=?' "$SID")" "1"
eq "audit_log entry"         "$(q "SELECT COUNT(*) FROM audit_log WHERE entity_type='shipment' AND entity_id=?" "$SID")" "1"

echo "== 3. Anonymous API must be blocked =="
ANON=$(curl -s -o /tmp/e2e-anon.json -w '%{http_code}' "$BASE/track-api.php?id=$TRK")
eq "anon HTTP (expect 403)" "$ANON" "403"
grep -q 'verify_required' /tmp/e2e-anon.json && ok "anon gets verify_required" || no "anon response missing verify_required"

echo "== 4. Wrong email AND phone must NOT unlock =="
rm -f /tmp/e2e-cust.jar
curl -s -c /tmp/e2e-cust.jar "$BASE/pre-track.php?id=$TRK" -o /tmp/e2e-pre.html
curl -s -b /tmp/e2e-cust.jar -c /tmp/e2e-cust.jar -o /tmp/e2e-pre-bad.html -w '%{http_code}' \
  --data-urlencode "tracking=$TRK" --data-urlencode "customer_name=E2E Tester" \
  --data-urlencode "customer_email=wrong$TS@example.com" --data-urlencode "customer_phone=+10000000000" \
  "$BASE/pre-track.php" >/dev/null
grep -q 'does not match' /tmp/e2e-pre-bad.html && ok "bad credentials rejected" || no "bad credentials not rejected"
BAD=$(curl -s -b /tmp/e2e-cust.jar -o /dev/null -w '%{http_code}' "$BASE/track-api.php?id=$TRK")
eq "still blocked after bad credentials" "$BAD" "403"

echo "== 5. Correct EMAIL (phone intentionally wrong) unlocks =="
rm -f /tmp/e2e-cust.jar
curl -s -c /tmp/e2e-cust.jar "$BASE/pre-track.php?id=$TRK" -o /dev/null
GOODCODE=$(curl -s -b /tmp/e2e-cust.jar -c /tmp/e2e-cust.jar -o /dev/null -w '%{http_code}' \
  --data-urlencode "tracking=$TRK" --data-urlencode "customer_name=E2E Tester" \
  --data-urlencode "customer_email=$EMAIL" --data-urlencode "customer_phone=+10000000000" \
  "$BASE/pre-track.php")
eq "pre-track redirect (expect 302)" "$GOODCODE" "302"
GOOD=$(curl -s -b /tmp/e2e-cust.jar -o /tmp/e2e-ok.json -w '%{http_code}' "$BASE/track-api.php?id=$TRK")
eq "verified customer HTTP (expect 200)" "$GOOD" "200"
php -r '$j=json_decode(file_get_contents("/tmp/e2e-ok.json"),true); echo "  api -> ".($j["status"]??"?")."|".($j["shipment"]["tracking_number"]??"?")."|verified=".($j["tracker"]["verified"]??"?")."|carrier=".json_encode($j["carrier"])."|eta=".($j["shipment"]["eta_text"]??"?")."\n";'

echo "== 5b. ?tracking= fallback also works =="
FB=$(curl -s -b /tmp/e2e-cust.jar -o /dev/null -w '%{http_code}' "$BASE/track-api.php?tracking=$TRK")
eq "tracking= fallback HTTP" "$FB" "200"

echo "== 6. Customer-driven movement (admin dashboard closed) =="
LAT0=$(q "SELECT current_lat FROM shipments WHERE id=?" "$SID")
REM0=$(q "SELECT distance_remaining FROM shipments WHERE id=?" "$SID")
sleep 3
curl -s -b /tmp/e2e-cust.jar -o /dev/null "$BASE/track-api.php?id=$TRK"
LAT1=$(q "SELECT current_lat FROM shipments WHERE id=?" "$SID")
REM1=$(q "SELECT distance_remaining FROM shipments WHERE id=?" "$SID")
echo "  before lat=$LAT0 remaining=$REM0"
echo "  after  lat=$LAT1 remaining=$REM1"
[ "$(num "$LAT1" "$LAT0")" = "0" ] && [ "$(php -r 'echo ((float)$argv[1]>(float)$argv[2])?1:0;' "$LAT1" "$LAT0")" = "1" ] && ok "latitude advanced" || no "latitude did not advance"
[ "$(php -r 'echo ((float)$argv[1]<(float)$argv[2])?1:0;' "$REM1" "$REM0")" = "1" ] && ok "distance_remaining decreased" || no "distance_remaining unchanged"

echo "== 7. Admin pause reflects on customer API =="
curl -s -b /tmp/e2e-admin.jar "$BASE/admin/shipment-control.php?id=$SID" -o /tmp/e2e-ctl.html
CTOK=$(csrf_js /tmp/e2e-ctl.html)
if [ -n "$CTOK" ]; then ok "control page CSRF token found"; else no "control page CSRF token missing"; fi
PCODE=$(curl -s -b /tmp/e2e-admin.jar -c /tmp/e2e-admin.jar -o /tmp/e2e-pause.json -w '%{http_code}' \
  --data-urlencode "csrf_token=$CTOK" --data-urlencode "shipment_id=$SID" \
  --data-urlencode "action=pause" "$BASE/admin/tracking-action.php")
eq "pause HTTP (expect 200)" "$PCODE" "200"
php -r '$j=json_decode(file_get_contents("/tmp/e2e-pause.json"),true); echo "  pause -> success=".json_encode($j["success"]??null)." msg=".($j["message"]??"?")."\n";'
DBRUN=$(q "SELECT is_running FROM tracking_controls WHERE shipment_id=?" "$SID")
eq "DB control is_running after pause" "$DBRUN" "0"
curl -s -b /tmp/e2e-cust.jar -o /tmp/e2e-held.json "$BASE/track-api.php?id=$TRK"
php -r '$j=json_decode(file_get_contents("/tmp/e2e-held.json"),true); $r=$j["control"]["is_running"]??null; if($r===false||$r===0){echo "  PASS  customer sees is_running=false\n";}else{echo "  FAIL  customer control.is_running=".json_encode($r)."\n"; exit(1);}' && ok "customer sees paused control" || no "customer still sees running"

echo "== 8. PHP warnings/errors in server log? =="
if grep -Ei 'PHP (Warning|Fatal|Parse|Notice|Deprecated)' /tmp/e2e-server.log >/tmp/e2e-loghits 2>/dev/null; then
  no "server log has PHP errors:"; cat /tmp/e2e-loghits | sed 's/^/    /'
else
  ok "server log clean (no PHP warnings/errors)"
fi

echo "== 9. Restoring database =="
restore
SRV=""
echo "  restored. shipments=$(q 'SELECT COUNT(*) FROM shipments') customers=$(q 'SELECT COUNT(*) FROM customers') audit=$(q 'SELECT COUNT(*) FROM audit_log')"

echo
echo "==== RESULT: $PASS passed, $FAIL failed ===="
exit $FAIL
