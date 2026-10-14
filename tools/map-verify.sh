#!/usr/bin/env bash
# CLI verification of the MapLibre map pages (no browser/WebGL needed).
# Checks: page 200, container present + sized, nxCreateMap called with a real
# style URL, library load order, inline-script syntax (node --check), assets 200,
# and a clean PHP server log.
set -u
cd /workspace/quiet-curie

BASE=http://127.0.0.1:8023
PORT=8023
DB=database/database.sqlite
BACKUP=/tmp/nx-db-mapverify-backup.sqlite
cp "$DB" "$BACKUP" || { echo "cannot back up DB"; exit 1; }
php -S 127.0.0.1:$PORT -t public > /tmp/nx-mapcheck-server.log 2>&1 &
SRV=$!
# Restore the DB too: the admin login below writes an audit_log row.
trap 'kill "$SRV" 2>/dev/null; cp "$BACKUP" "$DB" 2>/dev/null' EXIT

# wait until the server actually answers before logging in
for i in $(seq 1 40); do
  curl -s -o /dev/null "$BASE/admin/login.php" && break
  sleep 0.25
done

PASS=0; FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

# ---- login ----
curl -s -c /tmp/nx-map-admin.jar "$BASE/admin/login.php" -o /tmp/nx-map-login.html
TOK=$(php -r '$h=file_get_contents($argv[1]); echo preg_match("/name=\"csrf_token\"\s+value=\"([^\"]+)\"/",$h,$m)?$m[1]:"";' /tmp/nx-map-login.html)
CODE=$(curl -s -b /tmp/nx-map-admin.jar -c /tmp/nx-map-admin.jar -o /dev/null -w '%{http_code}' \
  --data-urlencode "csrf_token=$TOK" --data-urlencode "email=admins@logistics.com" \
  --data-urlencode "password=Admins@Logistics0" "$BASE/admin/login.php")
[ "$CODE" = "302" ] && ok "admin login (302)" || { no "admin login (got $CODE)"; }

SID=$(php -r '$d=new PDO("sqlite:database/database.sqlite"); echo (int)$d->query("SELECT MIN(id) FROM shipments WHERE status NOT IN (\"seized\")")->fetchColumn();')

check_page() {
  local name="$1" url="$2" container="$3"
  local code
  code=$(curl -s -b /tmp/nx-map-admin.jar -o "/tmp/nx-map-$name.html" -w '%{http_code}' "$url")
  [ "$code" = "200" ] && ok "$name HTTP 200" || { no "$name HTTP $code"; return; }

  grep -q "id=\"$container\"" "/tmp/nx-map-$name.html" \
    && ok "$name container #$container present" || no "$name container #$container missing"

  grep -q "nxCreateMap('$container'" "/tmp/nx-map-$name.html" \
    && ok "$name calls nxCreateMap('$container')" || no "$name nxCreateMap('$container') missing"

  grep -qE "nxCreateMap\('$container', \"https?://" "/tmp/nx-map-$name.html" \
    && ok "$name passes a real style URL" || no "$name style URL missing"

  # CSS height rule for the container (match across newlines)
  php -r '$h=file_get_contents($argv[1]); exit(preg_match("/#".preg_quote($argv[2],"/")."[^{]*\{[^}]*height/is",$h)?0:1);' \
    "/tmp/nx-map-$name.html" "$container" \
    && ok "$name container has a CSS height" || no "$name container height rule missing"

  local l1 l2
  l1=$(grep -n "vendor/maplibre/maplibre-gl.js" "/tmp/nx-map-$name.html" | head -1 | cut -d: -f1)
  l2=$(grep -n "js/nx-map.js" "/tmp/nx-map-$name.html" | head -1 | cut -d: -f1)
  if [ -n "$l1" ] && [ -n "$l2" ] && [ "$l1" -lt "$l2" ]; then
    ok "$name load order (maplibre before nx-map)"
  else
    no "$name load order wrong (maplibre@${l1:-none} nx-map@${l2:-none})"
  fi

  php -r '
    $h=file_get_contents($argv[1]);
    preg_match_all("#<script\b([^>]*)>(.*?)</script>#is",$h,$m,PREG_SET_ORDER);
    $i=0;
    foreach($m as $s){
      if(stripos($s[1],"src=")!==false) continue;
      if(preg_match("/type\s*=\s*\"[^\"]*(json|template)[^\"]*\"/i",$s[1])) continue;
      $i++; file_put_contents("/tmp/nx-inline-$argv[2]-$i.js",$s[2]);
    }
    echo $i;
  ' "/tmp/nx-map-$name.html" "$name" > /tmp/nx-inline-count
  local n bad=0
  n=$(cat /tmp/nx-inline-count)
  rm -f /tmp/nx-inline-$name-*.js.err
  for f in /tmp/nx-inline-$name-*.js; do
    [ -e "$f" ] || continue
    if ! node --check "$f" >/dev/null 2>&1; then
      bad=1
      echo "      syntax error in $f:"
      node --check "$f" 2>&1 | head -3 | sed 's/^/      /'
    fi
  done
  [ "$bad" = "0" ] && ok "$name inline scripts parse ($n blocks)" || no "$name inline script syntax error"
}

echo "== Admin map pages =="
check_page fleet   "$BASE/admin/fleet.php"                  nx-fleet-map
check_page control "$BASE/admin/shipment-control.php?id=$SID" globalTrackingMap
check_page route   "$BASE/admin/shipment-route.php?id=$SID"  nx-route-map

echo "== Library assets =="
for a in vendor/maplibre/maplibre-gl.js vendor/maplibre/maplibre-gl.css js/nx-map.js; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/assets/$a")
  [ "$c" = "200" ] && ok "asset $a -> 200" || no "asset $a -> $c"
done

echo "== Server log =="
if grep -Ei 'PHP (Warning|Fatal|Parse|Notice|Deprecated)' /tmp/nx-mapcheck-server.log >/tmp/nx-map-log 2>/dev/null; then
  no "PHP errors in server log"; sed 's/^/    /' /tmp/nx-map-log
else
  ok "server log clean (no PHP errors)"
fi

echo
echo "==== MAP CHECK: $PASS passed, $FAIL failed ===="
exit $FAIL
