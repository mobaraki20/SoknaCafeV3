<?php
declare(strict_types=1);
require_once __DIR__.'/../../apps/local-web/src/Core/Migrations.php';

final class FakeResult extends PDOStatement {
    private int $pos = 0;
    public function __construct(private array $rows) {}
    public function fetchColumn(int $column=0): mixed {
        $row = $this->rows[$this->pos++] ?? null;
        return $row === null ? false : array_values($row)[$column];
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {
        $row=$this->rows[$this->pos++] ?? null;
        return $row === null ? false : $row;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if($mode===PDO::FETCH_COLUMN) return array_map(fn($r)=>array_values($r)[0],$this->rows);
        return $this->rows;
    }
    public function closeCursor(): bool {return true;}
}
final class FakeConnection extends PDO {
    public array $seen=[];
    public function __construct(public string $scenario) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->seen[]=$query;
        $rows=[];
        if(str_contains($query,"TABLE_NAME='schema_migrations'")) $rows=[['n'=>($this->scenario==='fresh' ? 0 : 1)]];
        elseif(str_contains($query,"FROM schema_migrations WHERE")) $rows=($this->scenario==='already_applied') ? [['version'=>'0076_late_served_accounting'],['version'=>'0077_incremental_opening_inventory']]
            : ($this->scenario==='no_0076' ? [] : [['version'=>'0076_late_served_accounting']]);
        elseif(str_contains($query,'information_schema.COLUMNS c')) {
            $rows=[
              ['table_name'=>'inventory_items','engine'=>'InnoDB','column_type'=>'int(10) unsigned','column_key'=>'PRI'],
              ['table_name'=>'inventory_count_sessions','engine'=>'InnoDB','column_type'=>'bigint(20) unsigned','column_key'=>'PRI'],
              ['table_name'=>'inventory_count_lines','engine'=>'InnoDB','column_type'=>'bigint(20) unsigned','column_key'=>'PRI']
            ];
            if($this->scenario==='bad_parent') $rows[0]['engine']='MyISAM';
            if($this->scenario==='missing_parent') array_pop($rows);
        }
        elseif(str_contains($query,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_opening_activations'")) $rows=[['n'=>in_array($this->scenario,['existing_valid','existing_invalid_fk','existing_invalid_shape'],true) ? 1 : 0]];
        elseif(str_contains($query,'information_schema.REFERENTIAL_CONSTRAINTS')) $rows=$this->scenario==='fk_collision' ? [['CONSTRAINT_NAME'=>'fk_opening_activation_item']] : [];
        elseif(str_contains($query,'information_schema.COLUMNS WHERE')) {
            $rows=[
              ['column_name'=>'inventory_item_id','column_type'=>'int(10) unsigned','nullable'=>'NO'],
              ['column_name'=>'count_session_id','column_type'=>'bigint(20) unsigned','nullable'=>'NO'],
              ['column_name'=>'count_line_id','column_type'=>'bigint(20) unsigned','nullable'=>'NO'],
              ['column_name'=>'activated_at','column_type'=>'datetime','nullable'=>'NO'],
              ['column_name'=>'actor_user_id','column_type'=>'int(10) unsigned','nullable'=>'YES'],
            ];
            if($this->scenario==='existing_invalid_shape') $rows[1]['nullable']='YES';
        }
        elseif(str_contains($query,'information_schema.STATISTICS')) {
           $rows=[
               ['idx'=>'PRIMARY','seq'=>1,'col'=>'inventory_item_id','non_unique'=>0],
               ['idx'=>'count_line_id','seq'=>1,'col'=>'count_line_id','non_unique'=>0],
               ['idx'=>'idx_opening_activation_session','seq'=>1,'col'=>'count_session_id','non_unique'=>1],
               ['idx'=>'idx_opening_activation_session','seq'=>2,'col'=>'activated_at','non_unique'=>1],
           ];
        }
        elseif(str_contains($query,'information_schema.KEY_COLUMN_USAGE')) {
          $rows=[
               ['col'=>'inventory_item_id','ref_table'=>'inventory_items','ref_col'=>'id'],
               ['col'=>'count_session_id','ref_table'=>'inventory_count_sessions','ref_col'=>'id'],
               ['col'=>'count_line_id','ref_table'=>'inventory_count_lines','ref_col'=>'id'],
          ];
          if($this->scenario==='existing_invalid_fk') $rows[1]['ref_table']='users';
        }
        else throw new RuntimeException('Unexpected query: '.$query);
        return new FakeResult($rows);
    }
}
function check(string $scenario,bool $ok,bool $guardExpected): void {
    $db=new FakeConnection($scenario);
    $tmp=sys_get_temp_dir().'/sokna_0077_probe_'.bin2hex(random_bytes(4));
    if(!mkdir($tmp,0700,true)) throw new RuntimeException('mkdir failure');
    file_put_contents($tmp.'/0077_incremental_opening_inventory.sql','-- test-only schema guard fixture'."\n".'CREATE TABLE foo(id INT);');
    try {
        $migration=new Sokna\Local\Core\Migrations($db,$tmp);
        $caught=null;
        try{$catalog=$migration->catalog();assert(count($catalog)===1);}catch(Throwable $e){$caught=$e;}
        if(($caught===null)!==$ok)throw new RuntimeException($scenario.': unexpected '.($caught? $caught->getMessage():'PASS'));
        $hasGuard=count(array_filter($db->seen,fn($q)=>str_contains($q,'information_schema.COLUMNS c')))>0;
        if($hasGuard!==$guardExpected) throw new RuntimeException($scenario.': SQL schema guard not as expected');
        echo 'PASS '.$scenario.($caught ? ': '.$caught->getMessage():'')."\n";
    }finally {unlink($tmp.'/0077_incremental_opening_inventory.sql');rmdir($tmp);}
}
check('fresh',true,false);
check('no_0076',true,false);
check('already_applied',true,false);
check('healthy_new',true,true);
check('bad_parent',false,true);
check('missing_parent',false,true);
check('fk_collision',false,true);
check('existing_valid',true,true);
check('existing_invalid_shape',false,true);
check('existing_invalid_fk',false,true);