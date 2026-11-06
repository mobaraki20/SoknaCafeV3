-- R121 private staff staged accounts, intentionally non-functional before password activation.
-- Every account is INACTIVE with a non-password hash. Admin sets unique password in UI to activate.
-- RemoteCredentialService on admin password change handles Public/remote login.
-- DO NOT publish these names on public GitHub or insert any reusable default credential.;

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