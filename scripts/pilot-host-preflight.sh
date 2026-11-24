#!/usr/bin/env sh
#
# pilot-host-preflight.sh — pilot host readiness checks H1–H14
# (docs/pilot/PILOT-HOST-DEPLOYMENT-CHECKLIST.md). Read-only.
#
# Run from the repository root on the pilot host after `docker compose up`:
#   PILOT_HOST=pilot.campus.example scripts/pilot-host-preflight.sh
#
# Env:
#   PILOT_HOST         public DNS name of the gateway (required)
#   PILOT_HTTPS_PORT   default 443
#   PILOT_EXPECTED_IP  optional: IP the DNS name must resolve to
#   PILOT_INSECURE_TLS=1  rehearsal only: accept a self-signed certificate
#                         (H1 is then reported FAIL, never PASS)
#   COMPOSE            compose command, default:
#     docker compose --env-file apps/backend/.env -f docker-compose.yml -f docker-compose.pilot.yml
#
# Prints one line per check: PASS / FAIL / MANUAL. Exit 1 if any FAIL.

set -u

: "${PILOT_HOST:?PILOT_HOST is required}"
PORT="${PILOT_HTTPS_PORT:-443}"
BASE="https://${PILOT_HOST}:${PORT}"
COMPOSE="${COMPOSE:-docker compose --env-file apps/backend/.env -f docker-compose.yml -f docker-compose.pilot.yml}"
CURL="curl -sS --max-time 10"
[ "${PILOT_INSECURE_TLS:-0}" = "1" ] && CURL="$CURL -k"

fails=0
report() { printf '%-6s %-4s %s\n' "$2" "$1" "$3"; [ "$2" = "FAIL" ] && fails=$((fails + 1)); return 0; }
artisan() { $COMPOSE exec -T backend php artisan "$@" 2>/dev/null; }
# "docker compose port" prints nothing or ":0" for a port that is not published
published() { p=$($COMPOSE port "$1" "$2" 2>/dev/null); case "$p" in ''|*:0) echo "" ;; *) echo "$p" ;; esac; }

PREFLIGHT_JSON=$(artisan pilot:preflight --json --require-backup)
# "check name<TAB>status" lines, parsed once with the backend's PHP (host needs no PHP)
PREFLIGHT_ROWS=$(printf '%s' "$PREFLIGHT_JSON" | $COMPOSE exec -T backend php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["checks"]??[]) as $c){ echo $c["check"],"\t",$c["status"],"\n"; }' 2>/dev/null)
pf() { # status of one pilot:preflight check by exact name
  s=$(printf '%s\n' "$PREFLIGHT_ROWS" | awk -F'\t' -v n="$1" '$1==n {print $2; exit}')
  echo "${s:-MISSING}"
}
pf_all() { # PASS only if every named check is PASS
  for name in "$@"; do [ "$(pf "$name")" = "PASS" ] || { echo FAIL; return; }; done; echo PASS
}

# H1 TLS valid (trusted chain, >= 14 days left)
if [ "${PILOT_INSECURE_TLS:-0}" = "1" ]; then
  report H1 FAIL "TLS not validated (PILOT_INSECURE_TLS=1: self-signed rehearsal certificate)"
elif curl -sS --max-time 10 -o /dev/null "$BASE/healthz" 2>/dev/null; then
  end=$(echo | openssl s_client -connect "${PILOT_HOST}:${PORT}" -servername "$PILOT_HOST" 2>/dev/null | openssl x509 -noout -checkend 1209600 >/dev/null 2>&1 && echo ok)
  [ "$end" = "ok" ] && report H1 PASS "trusted certificate, valid >= 14 days" || report H1 FAIL "certificate expires within 14 days"
else
  report H1 FAIL "HTTPS with a trusted certificate failed for $BASE"
fi

# H2 DNS
ip=$(getent hosts "$PILOT_HOST" 2>/dev/null | awk '{print $1; exit}')
# nslookup fallback: only addresses listed after the answer's "Name:" line
# (the first "Address:" line is the DNS server itself)
[ -n "$ip" ] || ip=$(nslookup "$PILOT_HOST" 2>/dev/null | awk '/^Name:/ {n=1; next} n && /^Address/ {print $NF; exit}')
if [ -z "$ip" ]; then report H2 FAIL "$PILOT_HOST does not resolve"
elif [ -n "${PILOT_EXPECTED_IP:-}" ] && [ "$ip" != "$PILOT_EXPECTED_IP" ]; then report H2 FAIL "$PILOT_HOST -> $ip (expected $PILOT_EXPECTED_IP)"
else report H2 PASS "$PILOT_HOST -> $ip"; fi

# H3 production secrets installed (no empty/default/placeholder secrets)
report H3 "$(pf_all 'APP_KEY set' 'DB_PASSWORD not a default/dev value' 'AWS_SECRET_ACCESS_KEY not a default/dev value' 'AWS_ACCESS_KEY_ID not a default/dev value' 'REVERB_APP_SECRET not a default/dev value')" "pilot:preflight secrets group"

# H4 Redis private and password-protected
redis_port=$(published redis 6379)
noauth=$($COMPOSE exec -T redis redis-cli ping 2>&1)
if [ -n "$redis_port" ]; then report H4 FAIL "Redis published on $redis_port"
elif ! printf '%s' "$noauth" | grep -q NOAUTH; then report H4 FAIL "Redis answers without a password"
else report H4 PASS "not published; unauthenticated ping refused"; fi

# H5 PostgreSQL least-privilege roles
app_create=$($COMPOSE exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select has_schema_privilege('"'"'campus_app_user'"'"','"'"'public'"'"','"'"'CREATE'"'"')"' 2>/dev/null | tr -d '[:space:]')
supers=$($COMPOSE exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select count(*) from pg_roles where rolname in ('"'"'campus_migration_user'"'"','"'"'campus_app_user'"'"','"'"'campus_backup_user'"'"','"'"'campus_monitoring_user'"'"') and (rolsuper or rolcreaterole or rolcreatedb)"' 2>/dev/null | tr -d '[:space:]')
pg_port=$(published postgres 5432)
if [ "$(pf 'DB user is least-privilege (SEC-005)')" = "PASS" ] && [ "$app_create" = "f" ] && [ "$supers" = "0" ] && [ -z "$pg_port" ]; then
  report H5 PASS "app role has no schema CREATE; no privileged campus_* roles; not published"
else report H5 FAIL "app CREATE=$app_create privileged_roles=$supers published=${pg_port:-no}"; fi

# H6 object storage buckets and least-privilege credentials
init_exit=$(docker inspect -f '{{.State.ExitCode}}' "$($COMPOSE ps -a -q objectstore-init 2>/dev/null)" 2>/dev/null)
store_port=$(published objectstore 8333)
if [ "$init_exit" = "0" ] && [ "$(pf 'storage')" = "PASS" ] && [ -z "$store_port" ]; then
  report H6 PASS "objectstore-init exit 0; app buckets reachable with app credentials; not published"
else report H6 FAIL "objectstore-init exit=${init_exit:-none} storage=$(pf 'storage') published=${store_port:-no}"; fi

# H7 queue running and stable
q=$($COMPOSE ps -q queue 2>/dev/null)
q_state=$(docker inspect -f '{{.State.Status}} {{.RestartCount}}' "$q" 2>/dev/null)
case "$q_state" in "running 0") report H7 PASS "queue running, 0 restarts" ;; *) report H7 FAIL "queue state: ${q_state:-missing}" ;; esac

# H8 scheduler running with pilot jobs
s=$($COMPOSE ps -q scheduler 2>/dev/null)
s_state=$(docker inspect -f '{{.State.Status}} {{.RestartCount}}' "$s" 2>/dev/null)
sched=$($COMPOSE exec -T scheduler php artisan schedule:list 2>/dev/null)
if [ "$s_state" = "running 0" ] && printf '%s' "$sched" | grep -q 'system:monitor' && printf '%s' "$sched" | grep -q 'backup:run'; then
  report H8 PASS "scheduler running; system:monitor and backup:run scheduled"
else report H8 FAIL "scheduler state: ${s_state:-missing}; jobs incomplete"; fi

# H9 off-host backup reachable and holding the latest verified backups
report H9 "$(pf_all 'off-host backup target writable' 'POSTGRESQL backup copied off-host' 'STORAGE backup copied off-host')" "pilot:preflight off-host checks"

# H10 monitoring / alert path
full=$($CURL -o /dev/null -w '%{http_code} %{content_type}' "$BASE/health/full" 2>/dev/null)
if [ "${full%% *}" = "200" ] && printf '%s' "$full" | grep -q json && $COMPOSE exec -T backend php artisan system:monitor >/dev/null 2>&1; then
  report H10 PASS "/health/full JSON 200; system:monitor runs"
else report H10 FAIL "/health/full: ${full:-no response}"; fi
report H10 MANUAL "external uptime probe/paging on $BASE/health/full must be confirmed by the operator"

# H11 time synchronisation
if command -v timedatectl >/dev/null 2>&1; then
  [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = "yes" ] && report H11 PASS "host NTP synchronised" || report H11 FAIL "host NTP not synchronised"
else report H11 MANUAL "timedatectl unavailable: confirm NTP on the host"; fi
[ "$(pf 'app/database clock drift <= 5s')" = "PASS" ] || report H11 FAIL "app/database clock drift"

# H12 production frontend is the static build
page=$($CURL "$BASE/" 2>/dev/null)
if printf '%s' "$page" | grep -q '<div id="app">' && ! printf '%s' "$page" | grep -q '@vite/client'; then
  report H12 PASS "static bundle served (no Vite dev client)"
else report H12 FAIL "frontend is not the production static build"; fi

# H13 no default/shared accounts
report H13 "$(pf_all 'no seeded test accounts active' 'no active account uses a shared dev password')" "pilot:preflight account checks"

# H14 locked features false (config + effective + public API).
# vehicle/alpr are optional since ACR-VEH-01: their variables must be set explicitly (true or false).
feat=$($CURL -H 'Accept: application/json' "$BASE/api/v1/features" 2>/dev/null)
api_ok=$(printf '%s' "$feat" | grep -c '"door":false' 2>/dev/null)
if [ "$(pf_all 'FEATURE_DOOR_ENABLED explicitly false' 'FEATURE_VISITOR_ENABLED explicitly false' 'FEATURE_AI_ASSISTANT_ENABLED explicitly false' 'door effectively disabled' 'visitor effectively disabled' 'ai_assistant effectively disabled' 'FEATURE_VEHICLE_ENABLED explicitly set' 'FEATURE_ALPR_ENABLED explicitly set')" = "PASS" ] \
  && printf '%s' "$feat" | grep -q '"visitor":false' \
  && printf '%s' "$feat" | grep -q '"ai_assistant":false' && [ "$api_ok" -ge 1 ]; then
  report H14 PASS "kill switches engaged; /api/v1/features locked false; vehicle/alpr explicitly set"
else report H14 FAIL "locked feature not disabled or vehicle/alpr switch unset"; fi

# Overall application preflight
overall=$(printf '%s' "$PREFLIGHT_JSON" | grep -o '"result": *"[A-Z]*"' | head -1)
case "$overall" in *PASS*) report ALL PASS "pilot:preflight --require-backup" ;; *) report ALL FAIL "pilot:preflight --require-backup ${overall:-no output}" ;; esac

echo "host preflight: ${fails} failure(s)"
[ "$fails" -eq 0 ]
