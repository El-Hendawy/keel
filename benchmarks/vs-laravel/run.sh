#!/bin/sh
# usage: run.sh <name> <health-url> <order-url-prefix> <post-url> <order-id>
B=$(dirname "$0"); NAME=$1; HEALTH=$2; ORDER=$3; POST=$4; ID=$5
T='X-Tenant-Id: 11111111-1111-4111-8111-111111111111'
bench() { # label, wrk args...
  label=$1; shift
  wrk -t2 -c32 -d5s "$@" >/dev/null 2>&1         # warm-up
  wrk -t2 -c64 -d20s --latency "$@" > "$B/$NAME-$label.txt" 2>&1
  printf "%-8s %-10s %s | p50 %s p99 %s | non2xx %s\n" "$NAME" "$label" \
    "$(grep 'Requests/sec' $B/$NAME-$label.txt | awk '{print $2}') req/s" \
    "$(grep ' 50%' $B/$NAME-$label.txt | awk '{print $2}')" \
    "$(grep ' 99%' $B/$NAME-$label.txt | awk '{print $2}')" \
    "$(grep 'Non-2xx' $B/$NAME-$label.txt | awk '{print $NF}')"
}
bench health  "$HEALTH"
bench read    -H "$T" "$ORDER$ID"
bench invalid -s "$B/invalid.lua" "$POST"
bench create  -s "$B/post.lua" "$POST"
