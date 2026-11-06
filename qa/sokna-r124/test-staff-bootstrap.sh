#!/usr/bin/env bash
set -euo pipefail
export MYSQL_PWD=sokna
mysql=(mariadb --protocol=tcp -h127.0.0.1 -P3306 -usokna sokna_r115_full)
"${mysql[@]}" < qa/sokna-r124/0079_staff_qa.sql
"${mysql[@]}" < qa/sokna-r124/0079_staff_qa.sql
# Exactly six active role-limited employees, each with remote login and correct preparation bounds.
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users WHERE username LIKE 'qa.r121.%' AND active=1 AND role='operator'")" == "6" ]]
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN remote_user_credentials r ON r.user_id=u.id WHERE u.username LIKE 'qa.r121.%' AND r.source_mode='local_password'")" == "6" ]]
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_preparation_areas a ON a.user_id=u.id WHERE u.username IN ('qa.r121.a','qa.r121.b') AND a.area_key='kitchen'")" == "2" ]]
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_preparation_areas a ON a.user_id=u.id WHERE u.username IN ('qa.r121.c','qa.r121.d') AND a.area_key='bar'")" == "2" ]]
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_capabilities c ON c.user_id=u.id WHERE u.username LIKE 'qa.r121.%' AND c.capability IN ('remote_access','orders_floor','cashier_accounts') AND c.enabled=1")" == "18" ]]
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users u JOIN user_capabilities c ON c.user_id=u.id WHERE u.username IN ('qa.r121.e','qa.r121.f') AND c.capability='shift_supervision' AND c.enabled=1")" == "2" ]]
[[ "$("${mysql[@]}" -N -e "SELECT COUNT(*) FROM users WHERE username LIKE 'qa.r121.%'")" == "6" ]]
printf 'PASS sanitized R124 account upsert / permissions / remote / idempotence on real MariaDB\n'