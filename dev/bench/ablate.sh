#!/bin/zsh
# Measures every FastBoot switch alone: all on, each one off, all off. PHP medians from the profiler, wire medians through nginx.
export LC_ALL=C
cd "${MAGENTO_ROOT:-$(dirname "$0")/../../../..}"
URL=https://backend.localhost.reachdigital.io/graphql
KEY=$(cat var/fastboot/.key)
N=${N:-15}
FEATURES=(cache_files schema_array system_config_array view_config scopes_cache quote_without_connection deploy_config_unchanged schema_scalars area_config_diff validated_queries guest_tax_factor website_stores default_store placeholder_url)
cp app/etc/config.php dev/bench/queries/config.php.ablate-backup
set_switches() {  # $1: comma separated names to turn off, or "" for none
  php -r '$off = array_filter(explode(",", $argv[1])); $c = include "app/etc/config.php"; unset($c["fastboot"]); if ($off) { $c["fastboot"] = array_fill_keys($off, false); } file_put_contents("app/etc/config.php", "<?php\nreturn " . var_export($c, true) . ";\n");' "$1"
  PID=$(lsof -nP -iTCP:9084 -sTCP:LISTEN | awk 'NR==2{print $2}'); kill $PID; sleep 4
  for i in 1 2 3 4; do for Q in trivial all24; do curl -sk -o /dev/null -H 'Content-Type: application/json' -H "X-Catalog-Storefront: documents" -H "X-Catalog-Storefront-Key: $KEY" --data @dev/bench/queries/$Q.json $URL; done; done
}
measure() {  # $1: label
  local out=""
  for Q in trivial all24; do
    : > dev/bench/queries/ab.php.tmp; : > dev/bench/queries/ab.wire.tmp
    for i in $(seq 1 $N); do
      F=$(curl -sk -o /dev/null -D - -H 'Content-Type: application/json' -H "X-Catalog-Storefront: documents" -H "X-Catalog-Storefront-Key: $KEY" -H 'X-Mage-Profiler: json' --data @dev/bench/queries/$Q.json $URL | grep -i '^X-Mage-Profiler-Report' | awk '{print $2}' | tr -d '\r')
      python3 -c "import json;print(json.load(open('var/log/profiler/$F'))['meta']['total_ms'])" >> dev/bench/queries/ab.php.tmp
      curl -sk -o /dev/null -w '%{time_total}\n' -H 'Content-Type: application/json' -H "X-Catalog-Storefront: documents" -H "X-Catalog-Storefront-Key: $KEY" --data @dev/bench/queries/$Q.json $URL >> dev/bench/queries/ab.wire.tmp
    done
    P=$(sort -n dev/bench/queries/ab.php.tmp | awk -v n=$N '{a[NR]=$1} END {printf "%.1f", a[int((n+1)/2)]}')
    W=$(sort -n dev/bench/queries/ab.wire.tmp | awk -v n=$N '{a[NR]=$1} END {printf "%.1f", a[int((n+1)/2)]*1000}')
    out="$out $Q php $P wire $W;"
  done
  echo "$1:$out"
}
set_switches ""; measure "all on"
for f in $FEATURES; do set_switches "$f"; measure "off: $f"; done
set_switches "${(j:,:)FEATURES}"; measure "all off"
set_switches ""; measure "all on again"
cp dev/bench/queries/config.php.ablate-backup app/etc/config.php
PID=$(lsof -nP -iTCP:9084 -sTCP:LISTEN | awk 'NR==2{print $2}'); kill $PID; sleep 4
echo "done"
