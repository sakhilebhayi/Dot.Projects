#!/usr/bin/env bash
# Local load test (R-34 P12c). Run against a LOCAL server seeded with LoadSeeder, never production:
#   php artisan db:seed --class=LoadSeeder   (on a scratch PostgreSQL database)
#   scripts/load-test.sh http://127.0.0.1:8097
# Each page is requested by a different signed-in load person, 120 times, 4 at a time: inside the 300-a-minute
# per-person limit, so the numbers measure the app, not the rate limiter. Prints ab's median, 95th percentile and
# requests per second per page. Needs curl and ab (ApacheBench).
set -euo pipefail
BASE="${1:-http://127.0.0.1:8097}"
case "$BASE" in http://127.0.0.1:*|http://localhost:*) ;; *) echo "Local servers only." >&2; exit 2 ;; esac
N="${N:-120}"; C="${C:-4}"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

login() { # $1 = email; prints a Cookie header value for a signed-in session
  local jar="$WORK/$1.jar" token
  token=$(curl -s -c "$jar" -b "$jar" "$BASE/login" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
  curl -s -o /dev/null -c "$jar" -b "$jar" -X POST "$BASE/login" --data-urlencode "_token=$token" --data-urlencode "email=$1" --data-urlencode "password=password"
  # The session cookie is HttpOnly, which curl's jar writes as a "#HttpOnly_" line.
  awk '{sub(/^#HttpOnly_/, "")} !/^#/ && NF>=7 {printf "%s=%s; ", $6, $7}' "$jar"
}

PAGES=(/dashboard /portfolio /reports /workload /time /projects/1 /projects/1/work /projects/1/schedule /projects/1/board /projects/1/risks)
printf '%-24s %8s %8s %8s %8s\n' page median_ms p95_ms max_ms req_per_s
i=1
for page in "${PAGES[@]}"; do
  cookie="$(login "load${i}@example.test")"; i=$((i + 1))
  code=$(curl -s -o "$WORK/page" -w '%{http_code}' -H "Cookie: $cookie" "$BASE$page")
  [ "$code" = 200 ] || { echo "$page answered $code for a signed-in person" >&2; exit 1; }
  # A server misconfigured locally can answer 200 with a PHP warning: only a real page counts.
  grep -qi '<!DOCTYPE html' "$WORK/page" && ! grep -q '<b>Warning</b>' "$WORK/page" || { echo "$page did not return the page" >&2; exit 1; }
  out=$(ab -q -n "$N" -c "$C" -H "Cookie: $cookie" "$BASE$page" 2>&1)
  fails=$(echo "$out" | awk '/^Non-2xx responses/ {print $3}')
  [ -z "$fails" ] || { echo "$page: $fails non-2xx responses" >&2; exit 1; }
  printf '%-24s %8s %8s %8s %8s\n' "$page" \
    "$(echo "$out" | awk '/^  50%/ {print $2}')" "$(echo "$out" | awk '/^  95%/ {print $2}')" \
    "$(echo "$out" | awk '/^ 100%/ {print $2}')" "$(echo "$out" | awk '/^Requests per second/ {print $4}')"
done
out=$(ab -q -n "$N" -c "$C" "$BASE/ready" 2>&1)
printf '%-24s %8s %8s %8s %8s\n' /ready "$(echo "$out" | awk '/^  50%/ {print $2}')" "$(echo "$out" | awk '/^  95%/ {print $2}')" "$(echo "$out" | awk '/^ 100%/ {print $2}')" "$(echo "$out" | awk '/^Requests per second/ {print $4}')"
