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
           SUM(OCTET_LENGTH(TRIM(inv.name)))+COUNT(*)*40 AS safe_byte_bound
    FROM inventory_recipe_versions v
    JOIN inventory_recipe_components rc ON rc.recipe_version_id=v.id
    JOIN inventory_items inv ON inv.id=rc.inventory_item_id
    WHERE v.status='active'
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
        AND recipe.uncertain=0)
  )
  AND NOT (i.description <=> CASE
    WHEN cat.category_key IN ('coffee','cold-drinks','tea-herbal','herbal-tea','shakes-smoothies','smoothies','traditional-syrups','packaged-drinks')
         OR i.preparation_station IN ('cold_bar','hot_bar') THEN recipe.names_only
    ELSE recipe.names_with_amounts END);
