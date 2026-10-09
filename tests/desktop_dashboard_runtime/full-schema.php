<?php
/** Test-only column/FK fixture from the uploaded structure report. Defaults/indexes are synthetic. */
class DesktopFullSchemaFixture
{
    public static function create(array $tables): void
    {
        $db=\Illuminate\Support\Facades\DB::connection();
        // The uploaded report lists column/FK endpoints, not delete actions or
        // indexes. Use the original Spatie migration for its five reviewed tables
        // so role deletion and uniqueness exercise the actual cascade contract.
        $permissionTables=['permissions','roles','model_has_permissions','model_has_roles','role_has_permissions'];
        foreach($permissionTables as $table)if(!isset($tables[$table]))throw new \RuntimeException('The complete permission schema fixture is missing.');
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables)->up();
        foreach($tables as $name=>$table){
            if(in_array($name,$permissionTables,true))continue;
            $definitions=[];
            foreach($table['columns'] as $column=>$description){
                $nullable=str_contains($description,' nullable');$auto=str_contains($description,' auto_increment');
                $type=str_replace([' nullable',' auto_increment'],'',$description);
                if($auto)$clause=' NOT NULL AUTO_INCREMENT PRIMARY KEY';
                elseif($nullable)$clause=' NULL DEFAULT NULL';
                else{
                    $default="''";
                    if(preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|double|float|bit)/',$type))$default='0';
                    elseif(preg_match('/^enum\(\x27([^\x27]+)\x27/',$type,$match))$default=$db->getPdo()->quote($match[1]);
                    elseif(str_starts_with($type,'timestamp')||str_starts_with($type,'datetime'))$default="'2000-01-01 00:00:00'";
                    elseif($type==='date')$default="'2000-01-01'";
                    elseif($type==='time')$default="'00:00:00'";
                    $clause=' NOT NULL DEFAULT '.$default;
                }
                $definitions[]='`'.$column.'` '.$type.$clause;
            }
            if(isset($table['columns']['id'])&&!str_contains($table['columns']['id'],'auto_increment'))$definitions[]='PRIMARY KEY (`id`)';
            if($name==='go_stores')$definitions[]='PRIMARY KEY (`user_id`)';
            $db->statement('CREATE TABLE `'.$name.'` ('.implode(',',$definitions).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        foreach($tables as $name=>$table)foreach($table['references']??[] as $index=>$reference){
            if(in_array($name,$permissionTables,true))continue;
            $db->statement('ALTER TABLE `'.$name.'` ADD CONSTRAINT `fixture_'.substr(hash('sha256',$name.':'.$index),0,24).'` FOREIGN KEY (`'.$reference['column'].'`) REFERENCES `'.$reference['table'].'` (`'.$reference['target_column'].'`)');
        }
    }
}
