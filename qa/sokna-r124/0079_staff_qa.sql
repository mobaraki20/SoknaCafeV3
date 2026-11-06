-- QA ONLY: sanitized copies of six account grant statements (no production identities or hashes).
-- Customer-specific initial data. Fresh setup runs this after the catalog seed; upgrades run via Maintenance.
-- One-time migration: after install the user's later password changes are NOT overwritten.
-- Initial PIN is represented by separate salted bcrypt hashes, not stored in plaintext.
-- DO NOT distribute this migration in a generic product build for unrelated cafés.
-- No mandatory first-login password change. Do not broaden roles to admin.
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE EXISTS (
  SELECT 1 FROM users u JOIN (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r ON r.username=u.username
  WHERE u.display_name<>r.display_name OR u.role<>'operator'));

SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE EXISTS (
  SELECT 1 FROM users u JOIN (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r ON u.display_name=r.display_name
  WHERE u.username<>r.username));

SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE EXISTS (
  SELECT 1 FROM personnel p JOIN (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r ON r.display_name=p.display_name
  WHERE (SELECT COUNT(*) FROM personnel p2 WHERE p2.display_name=p.display_name)>1));

SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE EXISTS (
  SELECT 1 FROM personnel p JOIN (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r ON r.display_name=p.display_name
  LEFT JOIN users u ON u.username=r.username
  WHERE p.linked_user_id IS NOT NULL AND p.linked_user_id<>COALESCE(u.id,0)));

INSERT INTO users(username,password_hash,display_name,role,active)
SELECT r.username,CONCAT('!r121-pending-',SHA2(CONCAT(UUID(),RAND(),NOW(6)),256)),r.display_name,'operator',0
FROM (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r WHERE NOT EXISTS(SELECT 1 FROM users u WHERE u.username=r.username);

INSERT INTO user_capabilities(user_id,capability,enabled)
SELECT u.id,v.capability,1 FROM (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r JOIN users u ON u.username=r.username
 JOIN (SELECT 'orders_floor' capability UNION ALL SELECT 'cashier_accounts' UNION ALL SELECT 'remote_access'
 UNION ALL SELECT 'preparation' UNION ALL SELECT 'shift_supervision') v
 ON (v.capability NOT IN ('preparation','shift_supervision')
   OR (v.capability='preparation' AND r.kind='prep')
   OR (v.capability='shift_supervision' AND r.kind='shift'))
 ON DUPLICATE KEY UPDATE enabled=VALUES(enabled);

INSERT IGNORE INTO user_preparation_areas(user_id,area_key)
SELECT u.id,r.area FROM (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r JOIN users u ON u.username=r.username
WHERE r.kind='prep' AND r.area IN ('kitchen','bar');

UPDATE personnel p JOIN (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r ON p.display_name=r.display_name
 JOIN users u ON u.username=r.username SET p.linked_user_id=u.id
WHERE p.linked_user_id IS NULL
AND NOT EXISTS(SELECT 1 FROM personnel other WHERE other.linked_user_id=u.id AND other.id<>p.id);

INSERT INTO personnel(display_name,job_title,linked_user_id,active)
SELECT r.display_name,r.job_title,u.id,1 FROM (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'آشپزخانه' AS job_title,'kitchen' AS area,'prep' AS kind
UNION ALL
SELECT 'qa.r121.b','QA Staff B','آشپزخانه','kitchen','prep'
UNION ALL
SELECT 'qa.r121.c','QA Staff C','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.d','QA Staff D','بار سرد و گرم','bar','prep'
UNION ALL
SELECT 'qa.r121.e','QA Staff E','مسئول شیفت','','shift'
UNION ALL
SELECT 'qa.r121.f','QA Staff F','مسئول شیفت','','shift'
) r JOIN users u ON u.username=r.username
WHERE NOT EXISTS(SELECT 1 FROM personnel p WHERE p.linked_user_id=u.id OR p.display_name=r.display_name);

SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE
(SELECT COUNT(*) FROM users WHERE username IN ('qa.r121.a','qa.r121.b','qa.r121.c','qa.r121.d','qa.r121.e','qa.r121.f'))<>6);


-- Reject absent or conflicting matched accounts before touching any existing credentials.
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE EXISTS (
 SELECT 1 FROM users WHERE username IN ('qa.r121.a','qa.r121.b','qa.r121.c','qa.r121.d','qa.r121.e','qa.r121.f') AND (role<>'operator' OR ((username='qa.r121.a' AND display_name<>'QA Staff A') OR (username='qa.r121.b' AND display_name<>'QA Staff B') OR (username='qa.r121.c' AND display_name<>'QA Staff C') OR (username='qa.r121.d' AND display_name<>'QA Staff D') OR (username='qa.r121.e' AND display_name<>'QA Staff E') OR (username='qa.r121.f' AND display_name<>'QA Staff F')))));
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE
(SELECT COUNT(*) FROM users WHERE username IN ('qa.r121.a','qa.r121.b','qa.r121.c','qa.r121.d','qa.r121.e','qa.r121.f'))<>6);
-- Set requested initial password for precisely the six named accounts. This deliberately
-- resets these users' prior password if they already exist, as requested by the operator.
UPDATE users SET password_hash=CASE username
 WHEN 'qa.r121.a' THEN '$2y$10$1XOmd3H9/otZ9ZB0EXFlaOLdf5Bh2erjX4gsCqACCRQk19ekLUOGG'
 WHEN 'qa.r121.b' THEN '$2y$10$3yxIGgOADBKjXM4AuoZdcOmhGPJBsB8StSfKxUmKdCzp.NPLFQHdi'
 WHEN 'qa.r121.c' THEN '$2y$10$ATxJ7PLJ9G.N6r./kh8Rmu89dXiEGorgI6Ia.0tb5Y2x9L9PvYuTO'
 WHEN 'qa.r121.d' THEN '$2y$10$71QUUn8j2wvJ8XYg9j2pXew0NQf1qjBSJrWFOCmZbXbQrMNnvXrw2'
 WHEN 'qa.r121.e' THEN '$2y$10$pXRXJOSLGz9.2b8vVQiNKOtEWDRqbymHyLKv3wcdq7XRui6CTYPPu'
 WHEN 'qa.r121.f' THEN '$2y$10$bTrYeB7jmwgNOFN07az.6Og0yCGcN8sxxrlYXJmGk7xziLIGIqPne'
 ELSE password_hash END, active=1
WHERE username IN ('qa.r121.a','qa.r121.b','qa.r121.c','qa.r121.d','qa.r121.e','qa.r121.f') AND role='operator';
-- The remote credential table uses a distinct hash with the same user-provided credential.
-- Only the 6 identity-verified users are affected; repeated import is stable.
INSERT INTO remote_user_credentials(user_id,password_hash,credential_version,source_mode)
SELECT u.id,v.remote_hash,1,'local_password'
FROM (
SELECT 'qa.r121.a' AS username,'QA Staff A' AS display_name,'$2y$10$UjPccL0U87ckWmAj/RN46OnvmAYspp.0ux/ysgFi8yH26beai8GIa' AS remote_hash
UNION ALL
SELECT 'qa.r121.b' AS username,'QA Staff B' AS display_name,'$2y$10$89S7nx/v9nveYLWT7nYIf.HVyK59wCV.47Udu3fTbJ7zXRLAVVbEa' AS remote_hash
UNION ALL
SELECT 'qa.r121.c' AS username,'QA Staff C' AS display_name,'$2y$10$81JVxMvLsHK4906shVDxAuXV9r/18JxJKQ8Jy4xz5qM1GcXtxAr2K' AS remote_hash
UNION ALL
SELECT 'qa.r121.d' AS username,'QA Staff D' AS display_name,'$2y$10$1cexDTEubHfcUvHB1QI1jODny69GE1SO94OOZKmEZhomFuxVl6iW2' AS remote_hash
UNION ALL
SELECT 'qa.r121.e' AS username,'QA Staff E' AS display_name,'$2y$10$k.iZFt6QNi7aECJMwvZ95uYlgO.FJRH20blUSFC3LxdhwVWc27gj6' AS remote_hash
UNION ALL
SELECT 'qa.r121.f' AS username,'QA Staff F' AS display_name,'$2y$10$lc9KuqYeFOx/T.1M5CRThe.vQhdkPQBqh6xqL47IH72rff83HVDVC' AS remote_hash) v JOIN users u ON u.username=v.username AND u.display_name=v.display_name AND u.role='operator'
ON DUPLICATE KEY UPDATE
 credential_version=IF(remote_user_credentials.password_hash=VALUES(password_hash) AND remote_user_credentials.source_mode='local_password',remote_user_credentials.credential_version,remote_user_credentials.credential_version+1),
 password_hash=VALUES(password_hash),source_mode='local_password';
-- Sensitive hashes are not included in audit details.
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE
(SELECT COUNT(*) FROM users WHERE username IN ('qa.r121.a','qa.r121.b','qa.r121.c','qa.r121.d','qa.r121.e','qa.r121.f') AND active=1)<>6);
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail WHERE
(SELECT COUNT(*) FROM remote_user_credentials rc JOIN users u ON u.id=rc.user_id WHERE u.username IN ('qa.r121.a','qa.r121.b','qa.r121.c','qa.r121.d','qa.r121.e','qa.r121.f') AND rc.source_mode='local_password')<>6);