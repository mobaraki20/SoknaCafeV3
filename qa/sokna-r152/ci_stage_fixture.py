#!/usr/bin/env python3
"""R152 immutable-source Windows staging probe -- QA ONLY.

The temporary install-qualified manifest is synthetic and may ONLY be used on
an ephemeral GitHub Actions runner. It is never a product release or customer
download. This exercises ReleaseSetVerifierStager without bypassing the live
R125/DB backup and physical-host safety requirements.
"""
import hashlib
import io
import json
import pathlib
import sys
import zipfile

def must(value, label):
    if not value:
        raise RuntimeError("R152_STAGE_FIXTURE_" + label)
    print("PASS " + label, flush=True)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def archive(items):
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as z:
        for name, content in sorted(items.items()):
            entry = zipfile.ZipInfo(name, (2026, 11, 10, 14, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(entry, content)
    return out.getvalue()

def manifest_bytes(obj):
    return (json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()

def main():
    if len(sys.argv) != 5:
        raise SystemExit("Usage: ci_stage_fixture.py exact-R149B.zip R152-QA.zip fixture-output.zip stage-program.cs")
    parent_path, qa_path, out_path, program_path = [pathlib.Path(x).resolve() for x in sys.argv[1:]]
    must(sha(parent_path.read_bytes()) == "968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4",
         "EXACT_R149B_PARENT_SHA")
    with zipfile.ZipFile(parent_path) as z:
        parent = json.loads(z.read("release-set.json"))
    with zipfile.ZipFile(qa_path) as z:
        must(z.testzip() is None and set(z.namelist()) == {"release-set.json", "artifacts/local/web.zip"},
             "R152_OUTER_ZIP_CRC_AND_SHAPE")
        qa = json.loads(z.read("release-set.json"))
        web = z.read("artifacts/local/web.zip")
    must(qa["for_install"] is False and qa["production_pass"] is False and qa["install_plan"] == [],
         "PRODUCTION_BLOCKED_AS_DELIVERED")
    must(qa["signature"] is None and len(qa["supported_baselines"]) == 44,
         "EXACT_FORTY_FOUR_BASELINES")
    must(qa["targets"]["local"]["managed_files"] == 583 and
         qa["targets"]["local"]["migration_head"] == "0080_tea_herbal_legacy_family_repair",
         "EXACT_R152_TARGET_AND_SQL_HEAD")
    must(sha(web) == qa["artifacts"]["local-web"]["sha256"],
         "EXACT_R152_WEB_SHA")
    with zipfile.ZipFile(io.BytesIO(web)) as z:
        names = set(z.namelist())
        inventory = json.loads(z.read("resources/local-managed-files-v1.json"))
        must(len(names) == 583 and names == set(inventory["files"]),
             "EXACT_R152_583_MANAGED_FILES")
        sql = {name: sha(z.read(name)) for name in names if name.startswith("database/migrations/") and name.endswith(".sql")}
    with zipfile.ZipFile(parent_path) as z:
        with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as original:
            old_sql = {name: sha(original.read(name)) for name in original.namelist()
                       if name.startswith("database/migrations/") and name.endswith(".sql")}
    must(len(sql) == 80 and sql == old_sql,
         "ALL_EIGHTY_MIGRATIONS_BYTE_EXACT_UNCHANGED_FROM_QUALIFIED_R149B")
    cafe_version = "3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat"
    must(sum(r["local"]["version"] == cafe_version for r in qa["supported_baselines"]) == 1,
         "EXACT_CAFE_R112_ORIGIN_PRESENT_ONCE")
    must(any(r["local"]["version"] == parent["targets"]["local"]["version"] for r in qa["supported_baselines"]),
         "PREVIOUS_R149B_ORIGIN_PRESENT")

    # The only mutable object is a throwaway test fixture in RUNNER_TEMP. Never
    # upload, distribute, tag, or call it an installable Production ZIP.
    temporary = dict(qa)
    temporary["for_install"] = True
    temporary["production_pass"] = True
    temporary["status"] = "R152_CI_SYNTHETIC_STAGE_FIXTURE"
    temporary["install_plan"] = parent["install_plan"]
    temporary["qualification"] = parent["qualification"]
    temporary["qa_notes"] = {**qa["qa_notes"],
                             "qualification": "SYNTHETIC_IN_RUNNER_TEMP_NOT_REAL_R152_APPROVAL",
                             "physical_host": "NOT_TESTED",
                             "r125": "UNRESOLVED"}
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_bytes(archive({"release-set.json": manifest_bytes(temporary),
                                  "artifacts/local/web.zip": web}))
    must(sha(web) == sha(zipfile.ZipFile(out_path).read("artifacts/local/web.zip")),
         "SYNTHETIC_FIXTURE_PRESERVES_EXACT_SOURCE")
    original = program_path.read_text(encoding="utf-8")
    amended = original
    swaps = [
        ("==581", "==583"),
        ("581_FILE", "583_FILE"),
        ("581_FILES", "583_FILES"),
        ("==43", "==44"),
        ("43_KNOWN_BASELINES", "44_KNOWN_BASELINES"),
        ("3.0.0-g6.2-workspace.231eb-r149-catalog-ui-multibase-uat",
         "3.0.0-g6.2-workspace.231ec-r152-guest-ux-qa"),
        ("SOKNA_R149B_EXACT_WINDOWS_MAINTENANCE_0322_QUALIFIED_STAGE_PASS",
         "SOKNA_R152_SYNTHETIC_CI_STAGE_ONLY_PASS"),
    ]
    for old, new in swaps:
        must(old in amended, "SOURCE_STAGE_ASSERTION_PRESENT_" + str(swaps.index((old, new))))
        amended = amended.replace(old, new)
    program_path.write_text(amended, encoding="utf-8")
    print("CI_SYNTHETIC_FIXTURE_ONLY_NOT_RELEASED", sha(out_path.read_bytes()), flush=True)

if __name__ == "__main__":
    main()
