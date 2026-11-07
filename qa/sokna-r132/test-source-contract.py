#!/usr/bin/env python3
"""R132 no-host-mutation regression contracts. Run alongside real MariaDB/Windows jobs."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
CORE = ROOT / "windows/maintenance/src/Sokna.Maintenance.Core"

def source(file):
    return (CORE / file).read_text(encoding="utf-8")

class Contract(unittest.TestCase):
    def test_uploaded_package_cannot_stage_without_production_qualification(self):
        s = source("ReleaseSetVerifierStager.cs")
        p = s.index("public StagedReleaseReceipt VerifyAndStage(")
        stage = s[p:s.index("Directory.CreateDirectory(_paths.StagingRoot)", p)]
        self.assertIn("if (!manifest.ForInstall || !manifest.ProductionPass ||", stage)
        self.assertIn("ReleaseInstallQualificationGate.Require(manifest);", stage)
        self.assertIn("UAT|NOT", stage)
        self.assertNotIn("if (!operatorAuthorized && (!manifest.ForInstall", stage)

    def test_guard_checks_are_before_stop_and_before_forward(self):
        s = source("ProductionLocalSlotRoutine.cs")
        p = s.index("public RoutineLocalSlotResult ActivateRoutineSiblingRelease(")
        active = s[p:s.index("public RoutineLocalSlotResult RecoverRoutine(", p)]
        checks = [m.start() for m in re.finditer(
            r"RoutineLocalDatabase\.RequireReadOnlyGuardPreflight", active)]
        self.assertEqual(2, len(checks))
        stop = active.index("StopApacheAndProveExit(layout.ApacheExecutable)")
        forward = active.index('"DATABASE_FORWARD_INTENT"')
        self.assertLess(checks[0], stop)
        self.assertLess(stop, checks[1])
        self.assertLess(checks[1], forward)

    def test_php_preflight_reads_only_leading_select_guards(self):
        s=source("RoutineLocalDatabase.php")
        self.assertIn("SET TRANSACTION READ ONLY", s)
        self.assertIn("Migrations::splitStatements($sql)", s)
        self.assertIn("preg_match('/^SELECT", s)
        self.assertIn("if (isset($before[$version])) continue", s)
        self.assertIn("if ($pdo->inTransaction()) $pdo->rollBack()", s)
        self.assertIn("SOKNA_DB_SAFE_FAILURE migration=", s)
        self.assertNotIn("$e->databaseMessage", s)

    def test_durable_recovery_stays_fail_closed(self):
        s=source("ProductionLocalSlotRoutine.cs")
        self.assertIn("if (state.DatabaseMutationPerformed)", s)
        self.assertIn("LOCAL_DATABASE_FORWARD_RECOVERY_REQUIRED", s)
        self.assertIn("RoutineLocalDatabase.Apply(", s)
        self.assertNotIn("routine-force-rollback", s)

if __name__=="__main__":
    unittest.main(verbosity=2)
