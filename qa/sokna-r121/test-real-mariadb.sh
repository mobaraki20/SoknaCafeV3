#!/usr/bin/env bash
set -euo pipefail
DB=sokna_r115_full
HOST=127.0.0.1
file=qa/sokna-r121/0078_tea_herbal_three_sizes.sql
export MYSQL_PWD=sokna
mariadb --protocol=tcp -h"$HOST" -P3306 -usokna "$DB" < "$file"
# Post-migration family and prices: exact 9 families, 3 choices, and distinct new SKU.
rows=$(mariadb --protocol=tcp -N -h"$HOST" -P3306 -usokna "$DB" -e "SELECT COUNT(*) FROM item_variant_families f WHERE family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11') AND (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id)=3")
test "$rows" = 9
large=$(mariadb --protocol=tcp -N -h"$HOST" -P3306 -usokna "$DB" -e "SELECT COUNT(*) FROM items WHERE item_code LIKE 'szlarge-%' AND lifecycle_status='active'")
test "$large" = 9
mismatch=$(mariadb --protocol=tcp -N -h"$HOST" -P3306 -usokna "$DB" -e "SELECT COUNT(*) FROM item_variant_families f JOIN item_variant_members m1 ON m1.family_id=f.id AND REPLACE(m1.label,'‌',' ')='تک نفره' JOIN items a ON a.id=m1.item_id JOIN item_variant_members m2 ON m2.family_id=f.id AND m2.label='دو نفره' JOIN items b ON b.id=m2.item_id JOIN item_variant_members m3 ON m3.family_id=f.id AND m3.label='بزرگ' JOIN items c ON c.id=m3.item_id WHERE f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11') AND (b.price<>ROUND(a.price*1.70/5000)*5000 OR c.price<>ROUND(a.price*2.30/5000)*5000)")
test "$mismatch" = 0
before=$(mariadb --protocol=tcp -N -h"$HOST" -P3306 -usokna "$DB" -e 'SELECT COUNT(*) FROM items')
mariadb --protocol=tcp -h"$HOST" -P3306 -usokna "$DB" < "$file"
after=$(mariadb --protocol=tcp -N -h"$HOST" -P3306 -usokna "$DB" -e 'SELECT COUNT(*) FROM items')
test "$before" = "$after"
printf 'PASS R121 MariaDB 11.4 nine families, new large SKUs, price ratios, rerun idempotency, items %s\n' "$after"