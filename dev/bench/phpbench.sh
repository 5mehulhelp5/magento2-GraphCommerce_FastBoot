#!/bin/zsh
# Median PHP-side time of N profiled requests: total, and the point where the GraphQL query starts (the bootstrap).
# Usage: .tmp/perf/phpbench.sh <url> <query.json> <runs> <label>
export LC_ALL=C
cd "${MAGENTO_ROOT:-$(dirname "$0")/../../../..}"
U=$1; Q=$2; N=${3:-15}; L=$4
KEY=$(php bin/magento config:show catalog/storefront_documents/key)
for i in 1 2 3; do curl -sk -o /dev/null -H 'Content-Type: application/json' -H "X-Catalog-Storefront: documents" -H "X-Catalog-Storefront-Key: $KEY" --data @$Q $U; done
: > var/fastboot/.phpbench.$$
for i in $(seq 1 $N); do
  F=$(curl -sk -o /dev/null -D - -H 'Content-Type: application/json' -H "X-Catalog-Storefront: documents" -H "X-Catalog-Storefront-Key: $KEY" -H 'X-Mage-Profiler: json' --data @$Q $U | grep -i '^X-Mage-Profiler-Report' | awk '{print $2}' | tr -d '\r')
  python3 -c "
import json;d=json.load(open('var/log/profiler/$F'));q=[s for s in d['spans'] if s['name'].startswith('GRAPHQL:query')]
print(d['meta']['total_ms'], q[0]['start_ms'] if q else 0)" >> var/fastboot/.phpbench.$$
done
sort -n var/fastboot/.phpbench.$$ | awk -v n=$N -v l="$L" '{t[NR]=$1; b[NR]=$2} END {printf "%s: php total median %.1f ms, bootstrap median %.1f ms (n=%d)\n", l, t[int((n+1)/2)], b[int((n+1)/2)], n}'
sort -k2 -n var/fastboot/.phpbench.$$ | awk -v n=$N '{b[NR]=$2} END {printf "   bootstrap sorted median %.1f ms\n", b[int((n+1)/2)]}'
rm -f var/fastboot/.phpbench.$$
