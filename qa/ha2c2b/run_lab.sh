#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
command -v docker >/dev/null || { echo 'BLOCKED_DOCKER_NOT_AVAILABLE'; exit 2; }
docker compose version >/dev/null || { echo 'BLOCKED_COMPOSE_NOT_AVAILABLE'; exit 2; }
export SOKNA_LAB_PASSWORD="$(openssl rand -hex 32)"
export SOKNA_ANCHOR_LAB_PASSWORD="$(openssl rand -hex 32)"
export SOKNA_ISSUER_TEST_TOKEN="$(openssl rand -hex 32)"
project="ha2c2b_$(openssl rand -hex 5)"
cleanup() { docker compose -p "$project" -f compose.issuer.lab.yml down --volumes --remove-orphans >/dev/null 2>&1 || true; unset SOKNA_LAB_PASSWORD SOKNA_ANCHOR_LAB_PASSWORD SOKNA_ISSUER_TEST_TOKEN; }
trap cleanup EXIT
mkdir -p ci-artifacts
rm -f ci-artifacts/ha2c2b_split_issuer.json
status=0
docker compose -p "$project" -f compose.issuer.lab.yml up --build --abort-on-container-exit --exit-code-from consumer || status=$?
docker compose -p "$project" -f compose.issuer.lab.yml cp consumer:/opt/sokna-ha2c2b-lab/results/ha2c2b_split_issuer.json ci-artifacts/ha2c2b_split_issuer.json >/dev/null || true
php -r '
$f="ci-artifacts/ha2c2b_split_issuer.json";if(!is_file($f)){fwrite(STDERR,"MISSING_DB_EVIDENCE\n");exit(3);}
$d=json_decode(file_get_contents($f),true);
if(!is_array($d)||($d["status"]??"")!=="PASS_SPLIT_SIGNER_MARIADB_LAB_ONLY"||($d["passed"]??0)!==($d["total"]??-1)||($d["total"]??0)<20||($d["private_signing_key_in_consumer"]??true)!==false||($d["anchor_db_credentials_in_consumer"]??true)!==false||($d["offhost_operational_custody_proven"]??true)!==false||($d["execution_authorized"]??true)!==false||($d["for_install"]??true)!==false||($d["install_commit_proven"]??true)!==false){fwrite(STDERR,"HA2C2B_EVIDENCE_REJECTED\n");exit(3);}
echo "HA2C2B_LAB_SPLIT_ISSUER_VERIFIED\n";
' || exit 3
exit "$status"
