"""Release DB source gate: synthetic migration history and negative regressions."""
from __future__ import annotations
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import db_gate
import source_gate
from audit import sha


def packed(files: dict[str, bytes]) -> bytes:
    obj=io.BytesIO()
    with zipfile.ZipFile(obj,"w",zipfile.ZIP_DEFLATED) as z:
        for n,b in sorted(files.items()):
            z.writestr(n,b)
    return obj.getvalue()


class DBMigrationGate(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.p=pathlib.Path(self.tmp.name)
        self.common={
            "VERSION.txt":b"test-local-v1\n",
            "bootstrap.php":b"<?php\n",
            "database/migrations/0001_create.sql":b"CREATE TABLE orders(id INT);\n",
            "database/migrations/0002_add.sql":b"ALTER TABLE orders ADD COLUMN amount INT;\n",
        }

    def tearDown(self):
        self.tmp.cleanup()

    def make(self, files, component="local"):
        zip_data=packed(files)
        source=self.p/"source.zip"
        source.write_bytes(zip_data)
        pin={
            "zip_sha256":sha(zip_data),
            "source_tree_sha256":source_gate.source_tree(
                {n:(sha(b),len(b)) for n,b in files.items()}),
            "file_count":len(files),
            "original_version":files["VERSION.txt"].decode().strip(),
            "migration_count":len([n for n in files if n.startswith("database/migrations/")])
        }
        reg={"format":"sokna-release-source-register-v1",
             "checkpoints":{"T":{"status":"SOURCE_ONLY_NOT_INSTALLABLE",
                                  "sources":{component:pin}}}}
        registry=self.p/"register.json"
        registry.write_text(json.dumps(reg))
        return source,registry

    def old(self, files, kind="source"):
        old=self.p/"old.zip"
        if kind=="source":
            old.write_bytes(packed(files))
        elif kind=="local-maintenance":
            old.write_bytes(packed({"release-set.json":b"{}","artifacts/local/web.zip":packed(files)}))
        elif kind=="public-update":
            old.write_bytes(packed({"manifest.json":b"{}",
                                    **{"payload/"+n:v for n,v in files.items()}}))
        return old,sha(old.read_bytes())

    def test_same_sql_from_exact_baseline_passes(self):
        src,reg=self.make(self.common)
        baseline,digest=self.old(self.common,"local-maintenance")
        r=db_gate.analyze(src,"local","T",reg,baseline,"local-maintenance",digest)
        self.assertEqual(r["static"],"PASS",r)
        self.assertEqual(r["migration_count"],2)
        self.assertEqual(r["new_migrations"],[])
        self.assertFalse(r["db_install_qualified"])

    def test_missing_baseline_is_not_proven_even_if_static_pass(self):
        src,reg=self.make(self.common)
        r=db_gate.analyze(src,"local","T",reg)
        self.assertEqual(r["static"],"PASS",r)
        self.assertIn("PREVIOUS_INSTALLED_MIGRATIONS_NOT_COMPARED",r["not_proven"])

    def test_previous_sql_modification_is_blocked(self):
        new=dict(self.common)
        new["database/migrations/0001_create.sql"]=b"CREATE TABLE orders(id VARCHAR(5));\n"
        src,reg=self.make(new)
        old,digest=self.old(self.common)
        r=db_gate.analyze(src,"local","T",reg,old,"source",digest)
        self.assertIn("ALREADY_APPLIED_MIGRATION_SQL_CHANGED",r["errors"])

    def test_previous_migration_deletion_is_blocked(self):
        new=dict(self.common)
        new.pop("database/migrations/0002_add.sql")
        src,reg=self.make(new)
        old,digest=self.old(self.common)
        r=db_gate.analyze(src,"local","T",reg,old,"source",digest)
        self.assertIn("ALREADY_APPLIED_MIGRATION_REMOVED",r["errors"])

    def test_safe_append_requires_disposable_real_db(self):
        new=dict(self.common)
        new["database/migrations/0003_note.sql"]=b"ALTER TABLE orders ADD COLUMN memo TEXT;\n"
        src,reg=self.make(new)
        old,digest=self.old(self.common)
        r=db_gate.analyze(src,"local","T",reg,old,"source",digest)
        self.assertEqual(r["static"],"PASS",r)
        self.assertEqual(r["new_migrations"],["database/migrations/0003_note.sql"])
        self.assertIn("NEW_MIGRATION_REQUIRES_REAL_UPGRADE_BACKUP_AND_RESTORE_TEST",r["not_proven"])

    def test_destructive_new_migration_blocks(self):
        new=dict(self.common)
        new["database/migrations/0003_drop.sql"]=b"-- comment\nDROP TABLE orders;\n"
        src,reg=self.make(new)
        old,digest=self.old(self.common)
        r=db_gate.analyze(src,"local","T",reg,old,"source",digest)
        self.assertIn("NEW_MIGRATION_CONTAINS_POTENTIALLY_DESTRUCTIVE_SQL",r["errors"])

    def test_baseline_zip_hash_pin(self):
        src,reg=self.make(self.common)
        old,digest=self.old(self.common)
        r=db_gate.analyze(src,"local","T",reg,old,"source","0"*64)
        self.assertIn("HISTORICAL_BASELINE_ZIP_SHA_MISMATCH",r["errors"])

    def test_public_update_baseline_format(self):
        f=dict(self.common)
        f["VERSION.txt"]=b"old-public-v1\n"
        f["src/Update/emergency-owner-v1.json"]=b'{"release_version":"old-public-v1"}'
        src,reg=self.make(f,"public")
        old,digest=self.old(f,"public-update")
        r=db_gate.analyze(src,"public","T",reg,old,"public-update",digest)
        self.assertEqual(r["static"],"PASS",r)
        self.assertEqual(r["baseline_migration_count"],2)


if __name__=="__main__":
    unittest.main(verbosity=2)
