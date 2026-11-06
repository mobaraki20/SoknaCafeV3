#!/usr/bin/env bash
set -euo pipefail
export MYSQL_PWD=sokna
DB=sokna_r115_full
mysql_cmd=(mariadb --protocol=tcp -h127.0.0.1 -P3306 -usokna "$DB")
"${mysql_cmd[@]}" < qa/sokna-r121/0079_staff_sanitized.sql
# Six inert accounts, correct scoped area grants, remote/cashier/floor and supervision.
count=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM users WHERE username LIKE 'qa.r121.%' AND active=0 AND password_hash LIKE '!r121-pending-%'")
test "$count" = 6
other=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_preparation_areas a ON a.user_id=u.id WHERE u.username IN ('qa.r121.a','qa.r121.b') AND a.area_key='kitchen'")
test "$other" = 2
bar=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_preparation_areas a ON a.user_id=u.id WHERE u.username IN ('qa.r121.c','qa.r121.d') AND a.area_key='bar'")
test "$bar" = 2
shift=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_capabilities c ON c.user_id=u.id WHERE u.username IN ('qa.r121.e','qa.r121.f') AND c.capability='shift_supervision' AND c.enabled=1")
test "$shift" = 2
allcaps=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_capabilities c ON c.user_id=u.id WHERE u.username LIKE 'qa.r121.%' AND c.capability IN ('remote_access','cashier_accounts','orders_floor') AND c.enabled=1")
test "$allcaps" = 18
"${mysql_cmd[@]}" < qa/sokna-r121/0079_staff_sanitized.sql
count_after=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM users WHERE username LIKE 'qa.r121.%'")
test "$count_after" = 6
printf 'PASS simulated private staff import: six inert accounts, scoped areas, cashier/remote/floor and shift supervision, rerun\n'