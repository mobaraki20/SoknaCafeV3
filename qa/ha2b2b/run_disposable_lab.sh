#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if ! command -v docker >/dev/null 2>&1; then echo 'BLOCKED: Docker unavailable'; exit 2; fi
if ! docker compose version >/dev/null 2>&1; then echo 'BLOCKED: Compose unavailable'; exit 2; fi
if ! command -v openssl >/dev/null 2>&1; then echo 'BLOCKED: openssl unavailable'; exit 2; fi
export SOKNA_LAB_PASSWORD="$(openssl rand -hex 32)"
readonly project="sokna_ha2b2b_$(openssl rand -hex 6)"
cleanup() {
 docker compose -p "$project" -f compose.lab.yml down -v --remove-orphans >/dev/null 2>&1 || true
 unset SOKNA_LAB_PASSWORD
}
trap cleanup EXIT
mkdir -p ci-artifacts
status=0
docker compose -p "$project" -f compose.lab.yml up --build --abort-on-container-exit --exit-code-from test || status=$?
for name in real_mariadb_result.json real_mariadb_crash_matrix.json; do
  docker compose -p "$project" -f compose.lab.yml cp "test:/opt/sokna-ha2-lab/results/$name" "ci-artifacts/$name" >/dev/null 2>&1 || true
done
# A 0 exit status alone is not proof. Both reports must independently confirm actual MariaDB and PASS.
php -r '
$names=["real_mariadb_result.json"=>"PASS_REAL_MARIADB_LAB_ONLY", "real_mariadb_crash_matrix.json"=>"PASS_REAL_MARIADB_CRASH_MATRIX_LAB_ONLY"];
foreach($names as $name=>$expected){$f="ci-artifacts/".$name;
if(!is_file($f)) {fwrite(STDERR,"MISSING_EVIDENCE: $name\n");exit(3);}
$d=json_decode(file_get_contents($f),true);
if(!is_array($d)||($d["status"]??"")!==$expected||($d["actual_mariadb_exercised"]??false)!==true||($d["execution_authorized"]??true)!==false||($name==="real_mariadb_result.json"?($d["installed_release_proven"]??true)!==false:(($d["for_install"]??true)!==false||($d["install_commit_proven"]??true)!==false))||($d["passed"]??-1)!==($d["total"]??-2)||($d["total"]??0)<1){fwrite(STDERR,"GATE_FAILED: $name\n");exit(3);}
}
echo "REAL_DB_EVIDENCE_GATE_PASS_NON_PRODUCTION\n";
' || exit 3
exit "$status"
