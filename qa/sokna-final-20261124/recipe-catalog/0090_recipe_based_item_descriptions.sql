-- 0090: one-time description-only refresh, AFTER approved recipe import 0089.
-- Existing prices, inventory, recipe versions, media and historical snapshots are not written.
-- Active recipe names are authoritative for bar. Food amounts are shown only when all
-- recorded quantities are known and positive. No recipe / ambiguous / unknown => preserve.
UPDATE items i
JOIN categories cat ON cat.id=i.category_id
JOIN (
    SELECT v.menu_item_id,
           GROUP_CONCAT(TRIM(inv.name) ORDER BY rc.id SEPARATOR '، ') AS names_only,
           GROUP_CONCAT(CONCAT(TRIM(inv.name),' ',rc.quantity_base,' ',
               CASE inv.base_unit WHEN 'g' THEN 'گرم' WHEN 'ml' THEN 'میلی‌لیتر' WHEN 'count' THEN 'عدد' END)
               ORDER BY rc.id SEPARATOR '، ') AS names_with_amounts,
           SUM(CASE WHEN rc.quantity_status='known' AND rc.quantity_base>0
                     AND inv.base_unit IN ('g','ml','count') THEN 0 ELSE 1 END) AS uncertain,
           MIN(CASE WHEN v.review_status='ready' AND v.completeness_status='complete' THEN 1 ELSE 0 END) AS food_confirmed,
           SUM(OCTET_LENGTH(TRIM(inv.name)))+COUNT(*)*40 AS safe_byte_bound
    FROM inventory_recipe_versions v
    JOIN inventory_recipe_components rc ON rc.recipe_version_id=v.id
    JOIN inventory_items inv ON inv.id=rc.inventory_item_id
    WHERE v.status='active' AND COALESCE(v.source_ref,'')<>'owner_approved_bar_recipes_20261124'
      AND (SELECT COUNT(*) FROM inventory_recipe_versions vv
           WHERE vv.menu_item_id=v.menu_item_id AND vv.status='active')=1
    GROUP BY v.menu_item_id
    HAVING COUNT(*)>0 AND SUM(CASE WHEN TRIM(inv.name)='' THEN 1 ELSE 0 END)=0
       AND safe_byte_bound < LEAST(@@session.group_concat_max_len,60000)
) recipe ON recipe.menu_item_id=i.id
SET i.description=CASE
    WHEN cat.category_key IN ('coffee','cold-drinks','tea-herbal','herbal-tea','shakes-smoothies','smoothies','traditional-syrups','packaged-drinks')
         OR i.preparation_station IN ('cold_bar','hot_bar') THEN recipe.names_only
    ELSE recipe.names_with_amounts
END
WHERE i.active=1 AND i.lifecycle_status='active' AND i.sellable_kind='menu_item'
  AND cat.category_key<>'internal-services'
  AND (
    cat.category_key IN ('coffee','cold-drinks','tea-herbal','herbal-tea','shakes-smoothies','smoothies','traditional-syrups','packaged-drinks')
    OR i.preparation_station IN ('cold_bar','hot_bar')
    OR ((cat.category_key IN ('food-snacks','finger-food','foods','main-dishes','pickles-sides') OR i.preparation_station='kitchen')
        AND recipe.uncertain=0 AND recipe.food_confirmed=1)
  )
  AND NOT (i.description <=> CASE
    WHEN cat.category_key IN ('coffee','cold-drinks','tea-herbal','herbal-tea','shakes-smoothies','smoothies','traditional-syrups','packaged-drinks')
         OR i.preparation_station IN ('cold_bar','hot_bar') THEN recipe.names_only
    ELSE recipe.names_with_amounts END);

-- Exact user terminology for the new recipes, including mint whose base weight is not known.
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='کره لوتوس، بیسکویت لوتوس، بستنی وانیلی، شیر' WHERE i.item_code='1104' AND NOT (i.description <=> 'کره لوتوس، بیسکویت لوتوس، بستنی وانیلی، شیر');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='کره بادام‌زمینی، دارچین، کنجد، موز، جو دوسر، شیر' WHERE i.item_code='1113' AND NOT (i.description <=> 'کره بادام‌زمینی، دارچین، کنجد، موز، جو دوسر، شیر');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='موز، بستنی وانیلی، شیر' WHERE i.item_code='1010' AND NOT (i.description <=> 'موز، بستنی وانیلی، شیر');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='موز، یخ دستگاه، شیر، عسل' WHERE i.item_code='1009' AND NOT (i.description <=> 'موز، یخ دستگاه، شیر، عسل');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='انبه منجمد، نکتار انبه، سیروپ انبه' WHERE i.item_code='1110' AND NOT (i.description <=> 'انبه منجمد، نکتار انبه، سیروپ انبه');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='بستنی وانیلی، انبه منجمد، سیروپ انبه' WHERE i.item_code='1109' AND NOT (i.description <=> 'بستنی وانیلی، انبه منجمد، سیروپ انبه');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='بستنی شکلاتی، شیر، سس شکلات' WHERE i.item_code='1103' AND NOT (i.description <=> 'بستنی شکلاتی، شیر، سس شکلات');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='بستنی وانیلی، سیروپ توت‌فرنگی، شیر' WHERE i.item_code='1112' AND NOT (i.description <=> 'بستنی وانیلی، سیروپ توت‌فرنگی، شیر');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='توت‌فرنگی منجمد، انبه منجمد، نکتار انبه، سیروپ انبه' WHERE i.item_code='1111' AND NOT (i.description <=> 'توت‌فرنگی منجمد، انبه منجمد، نکتار انبه، سیروپ انبه');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='بستنی وانیلی، کره بادام‌زمینی، پنیر ویلی' WHERE i.item_code='1107' AND NOT (i.description <=> 'بستنی وانیلی، کره بادام‌زمینی، پنیر ویلی');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='گردو، ارده، کنجد، دارچین، بستنی وانیلی، شیر' WHERE i.item_code='1101' AND NOT (i.description <=> 'گردو، ارده، کنجد، دارچین، بستنی وانیلی، شیر');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='بستنی وانیلی، قهوه گلد' WHERE i.item_code='1108' AND NOT (i.description <=> 'بستنی وانیلی، قهوه گلد');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='گردو، ارده، کنجد، دارچین، بستنی وانیلی، قهوه اسپرسو' WHERE i.item_code='1102' AND NOT (i.description <=> 'گردو، ارده، کنجد، دارچین، بستنی وانیلی، قهوه اسپرسو');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='بستنی وانیلی، قهوه اسپرسو' WHERE i.item_code='905' AND NOT (i.description <=> 'بستنی وانیلی، قهوه اسپرسو');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='شیر، قهوه گلد، شکر قهوه‌ای، سیروپ وانیل' WHERE i.item_code='1019' AND NOT (i.description <=> 'شیر، قهوه گلد، شکر قهوه‌ای، سیروپ وانیل');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='لیمو، نعنا، سیروپ نعنا، آب گازدار' WHERE i.item_code='1005' AND NOT (i.description <=> 'لیمو، نعنا، سیروپ نعنا، آب گازدار');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='لیمو، نعنا، سیروپ توت‌فرنگی، شربت فیمتو، آب گازدار' WHERE i.item_code='1018' AND NOT (i.description <=> 'لیمو، نعنا، سیروپ توت‌فرنگی، شربت فیمتو، آب گازدار');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='لیمو، نعنا، سیروپ بلوکاراسائو، آب گازدار' WHERE i.item_code='1016' AND NOT (i.description <=> 'لیمو، نعنا، سیروپ بلوکاراسائو، آب گازدار');
UPDATE items i JOIN inventory_recipe_versions v ON v.menu_item_id=i.id AND v.status='active' AND v.source_ref='owner_approved_bar_recipes_20261124' SET i.description='سیروپ آلبالو، سیروپ بلوکاراسائو، نوشیدنی آلوئه‌ورا، آب گازدار، لیمو' WHERE (i.item_code='SOKNA-BLACK-MOJITO-20261124' OR i.name IN ('بلک موهیتو','بلک مویتو')) AND NOT (i.description <=> 'سیروپ آلبالو، سیروپ بلوکاراسائو، نوشیدنی آلوئه‌ورا، آب گازدار، لیمو');
