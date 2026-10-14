#!/usr/bin/env bash
# Feature endpoint checks: backup, password reset, chatbot, receipt, remember-me.
set -u
cd /workspace/quiet-curie
BASE=http://127.0.0.1:8025
DB=database/database.sqlite
BACKUP=/tmp/nx-db-feat-backup.sqlite
SRV=""
PASS=0; FAIL=0
ok(){ echo "  PASS  $1"; PASS=$((PASS+1)); }
no(){ echo "  FAIL  $1"; FAIL=$((FAIL+1)); }

cp "$DB" "$BACKUP" || { echo "cannot back up DB"; exit 1; }
restore(){ [ -n "$SRV" ] && kill "$SRV" 2>/dev/null; cp "$BACKUP" "$DB" 2>/dev/null; }
trap restore EXIT
php -S 127.0.0.1:8025 -t public > /tmp/feat-server.log 2>&1 &
SRV=$!
sleep 1.5
csrf(){ php -r '$h=file_get_contents($argv[1]); echo preg_match("/name=\"csrf_token\"\s+value=\"([^\"]+)\"/",$h,$m)?$m[1]:"";' "$1"; }

# Admin session
curl -s -c /tmp/nx-feat-admin.jar "$BASE/admin/login.php" -o /tmp/nx-feat-login.html
TOK=$(csrf /tmp/nx-feat-login.html)
curl -s -b /tmp/nx-feat-admin.jar -c /tmp/nx-feat-admin.jar -o /dev/null \
  --data-urlencode "csrf_token=$TOK" --data-urlencode "email=admins@logistics.com" \
  --data-urlencode "password=Admins@Logistics0" "$BASE/admin/login.php"

echo "== 1. One-click database backup =="
BC=$(curl -s -b /tmp/nx-feat-admin.jar -D /tmp/nx-backup-headers.txt -o /tmp/nx-backup.sqlite -w '%{http_code}' "$BASE/admin/backup.php")
[ "$BC" = "200" ] && ok "backup HTTP 200" || no "backup HTTP $BC"
MAGIC=$(head -c 15 /tmp/nx-backup.sqlite)
[ "$MAGIC" = "SQLite format 3" ] && ok "download is a real SQLite file ($(stat -c%s /tmp/nx-backup.sqlite) bytes)" || no "download not SQLite (got '$MAGIC')"
grep -qi "attachment" /tmp/nx-backup-headers.txt && ok "served as a file download" || no "missing attachment header"

echo "== 2. Password reset (PHP mail) =="
PRC=$(curl -s -o /tmp/nx-pr.html -w '%{http_code}' "$BASE/admin/password-reset.php")
[ "$PRC" = "200" ] && ok "reset page HTTP 200" || no "reset page HTTP $PRC"
grep -q 'name="email"' /tmp/nx-pr.html && ok "reset form present" || no "reset form missing"

echo "== 3. Chatbot is gated for unverified visitors =="
CHAT=$(curl -s -X POST -H 'Content-Type: application/json' \
  --data '{"message":"Where is my shipment?","tracking":"NX-2026-0001"}' \
  -o /tmp/nx-chat-anon.json -w '%{http_code}' "$BASE/api/chat.php")
[ "$CHAT" = "403" ] && ok "anonymous chat -> 403 (gated)" || no "anonymous chat -> $CHAT (want 403)"
# A shipment with no contact info stays reachable (flagged Unverified).
CHAT2=$(curl -s -X POST -H 'Content-Type: application/json' \
  --data '{"message":"status?","tracking":"TEST-001"}' \
  -o /tmp/nx-chat-open.json -w '%{http_code}' "$BASE/api/chat.php")
[ "$CHAT2" = "200" ] && ok "no-contact shipment chat -> 200" || no "no-contact chat -> $CHAT2 (want 200)"

echo "== 4. Verified receipt vs anonymous gate =="
rm -f /tmp/nx-feat-cust.jar
curl -s -c /tmp/nx-feat-cust.jar "$BASE/pre-track.php?id=NX-2026-0001" -o /dev/null
curl -s -b /tmp/nx-feat-cust.jar -c /tmp/nx-feat-cust.jar -o /dev/null -D /tmp/nx-pretrack-headers.txt \
  --data-urlencode "tracking=NX-2026-0001" --data-urlencode "customer_name=James Bond" \
  --data-urlencode "customer_email=james@example.com" --data-urlencode "customer_phone=08000000001" \
  "$BASE/pre-track.php"
grep -qi "set-cookie" /tmp/nx-pretrack-headers.txt && ok "pre-track sets a remember-me cookie" || no "no remember-me cookie set"
RC=$(curl -s -b /tmp/nx-feat-cust.jar -o /tmp/nx-receipt.html -w '%{http_code}' "$BASE/receipt.php?id=NX-2026-0001")
[ "$RC" = "200" ] && ok "receipt HTTP 200 for verified customer" || no "receipt HTTP $RC"
grep -q 'NX-2026-0001' /tmp/nx-receipt.html && ok "receipt shows tracking number" || no "receipt missing tracking number"
ARC=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/receipt.php?id=NX-2026-0001")
[ "$ARC" = "302" ] && ok "receipt redirects anonymous to pre-track" || no "anonymous receipt HTTP $ARC (want 302)"

echo "== 4b. Chatbot works for the verified customer =="
CHATV=$(curl -s -b /tmp/nx-feat-cust.jar -X POST -H 'Content-Type: application/json' \
  --data '{"message":"Where is my shipment?","tracking":"NX-2026-0001"}' \
  -o /tmp/nx-chat.json -w '%{http_code}' "$BASE/api/chat.php")
[ "$CHATV" = "200" ] && ok "verified chat HTTP 200" || no "verified chat HTTP $CHATV"
php -r '$j=json_decode(file_get_contents("/tmp/nx-chat.json"),true); $r=$j["reply"]??($j["message"]??""); echo "  reply: ".substr((string)$r,0,90)."\n";'
if php -r '$j=json_decode(file_get_contents("/tmp/nx-chat.json"),true); exit((isset($j["reply"]) && trim((string)$j["reply"])!=="")?0:1);'; then
  ok "verified chat returned a reply"
else
  no "verified chat reply empty"
fi

echo "== 5. Proof of delivery admin form =="
curl -s -b /tmp/nx-feat-admin.jar "$BASE/admin/proof-of-delivery.php?id=2" -o /tmp/nx-pod.html
grep -qi "signature\|file" /tmp/nx-pod.html && ok "proof-of-delivery upload form present" || no "proof-of-delivery form missing"

echo "== 6. Server log =="
grep -Ei 'PHP (Warning|Fatal|Parse|Notice|Deprecated)' /tmp/feat-server.log && no "PHP errors in log" || ok "server log clean"

restore; SRV=""
echo
echo "==== FEATURES: $PASS passed, $FAIL failed ===="
exit $FAIL
