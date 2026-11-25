#!/usr/bin/env python3
"""One reviewed, additive overlay on the already SHA-pinned consolidated Local source.
The factory still verifies the complete original source before invoking this function.
No historical migrations, installer, Public source or emergency files are modified.
"""
import hashlib,pathlib
H=lambda b:hashlib.sha256(b).hexdigest()
def apply(local):
    base=pathlib.Path(__file__).resolve().parent/'recipe-catalog'
    old=local['src/Core/Migrations.php']
    assert H(old)=='a2749167b3c45af75c77de4f4da3db7cecd05dfe093d358febff643565266ecb','MIGRATION_RUNNER_SOURCE_DRIFT'
    needle=b"|| str_starts_with($version, '0081_');"
    assert old.count(needle)==1
    local['src/Core/Migrations.php']=old.replace(needle,b"|| str_starts_with($version, '0081_') || in_array($version, ['0089_owner_approved_recipes_and_purchase_units','0090_recipe_based_item_descriptions'], true);")
    for name in ['0089_owner_approved_recipes_and_purchase_units.sql','0090_recipe_based_item_descriptions.sql']:
        key='database/migrations/'+name
        assert key not in local
        local[key]=(base/name).read_bytes()
    key='src/Domain/Reporting/ReportingService.php'
    old=local[key]
    assert H(old)=='151e776933c7d68c33b2b77d64f129d931ac1c14188b5c3cc46d0a0282f5f98a','REPORTING_SOURCE_DRIFT'
    needle=b"            [\n                'name'=>'Inventory_Movements',"
    assert old.count(needle)==1
    addition=r"""            [
                'name'=>'Inventory_Catalog',
                'headers'=>['inventory_item_id','item_code','name','category','base_unit','department','review_status','review_note','active'],
                'count'=>'SELECT COUNT(*) c FROM inventory_items',
                'sql'=>'SELECT id,item_code,name,category,base_unit,default_department,review_status,review_note,active FROM inventory_items ORDER BY item_code,id',
                'params'=>[],'ints'=>[0],'floats'=>[],'bools'=>[8],
            ],
            [
                'name'=>'Inventory_Purchase_Units',
                'headers'=>['purchase_unit_id','inventory_item_id','item_code','item_name','base_unit','purchase_unit_name','conversion_mode','base_quantity','review_status','note','active'],
                'count'=>'SELECT COUNT(*) c FROM inventory_purchase_units',
                'sql'=>'SELECT pu.id,pu.inventory_item_id,i.item_code,i.name,i.base_unit,pu.name,pu.conversion_mode,pu.base_quantity,pu.review_status,pu.note,pu.active FROM inventory_purchase_units pu JOIN inventory_items i ON i.id=pu.inventory_item_id ORDER BY i.item_code,pu.id',
                'params'=>[],'ints'=>[0,1,7],'floats'=>[],'bools'=>[10],
            ],
            [
                'name'=>'Inventory_Aliases',
                'headers'=>['alias_id','inventory_item_id','item_code','item_name','alias','review_status','note','active'],
                'count'=>'SELECT COUNT(*) c FROM inventory_item_aliases',
                'sql'=>'SELECT a.id,a.inventory_item_id,i.item_code,i.name,a.alias,a.review_status,a.note,a.active FROM inventory_item_aliases a JOIN inventory_items i ON i.id=a.inventory_item_id ORDER BY i.item_code,a.id',
                'params'=>[],'ints'=>[0,1],'floats'=>[],'bools'=>[7],
            ],
""".encode()
    local[key]=old.replace(needle,addition+needle)
    return local
