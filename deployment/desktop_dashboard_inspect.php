<?php
/** Read-only schema inspection. No business rows, column defaults, passwords, or configuration values. */
final class FasakhanstaDesktopSchemaInspection
{
    public static function report($app): array
    {
        $connection=$app['db']->connection();
        if($connection->getDriverName()!=='mysql')throw new RuntimeException('A MySQL-compatible schema is required for this inspection.');
        $database=$connection->getDatabaseName();$tables=[];
        $columns=$connection->select('SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name,COLUMN_TYPE AS column_type,IS_NULLABLE AS is_nullable,EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION',[$database]);
        foreach($columns as $c)$tables[$c->table_name]['columns'][]=['name'=>$c->column_name,'type'=>$c->column_type,'nullable'=>$c->is_nullable==='YES','extra'=>$c->extra];
        foreach($connection->select('SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,COLUMN_NAME AS column_name,NON_UNIQUE AS non_unique,SEQ_IN_INDEX AS position FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',[$database]) as $i){
            $tables[$i->table_name]['indexes'][$i->index_name]['unique']=!(bool)$i->non_unique;
            $tables[$i->table_name]['indexes'][$i->index_name]['columns'][]=$i->column_name;
        }
        foreach($connection->select('SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name,REFERENCED_TABLE_NAME AS target_table,REFERENCED_COLUMN_NAME AS target_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION',[$database]) as $f){
            $tables[$f->table_name]['references'][]=['column'=>$f->column_name,'table'=>$f->target_table,'target_column'=>$f->target_column];
        }
        $routes=[];
        foreach($app['router']->getRoutes() as $route){
            // Reading route actions does not instantiate controllers or run their constructors.
            $action=$route->getAction();$middleware=array_values(array_filter((array)($action['middleware']??[]),'is_string'));
            $routes[]=['name'=>$route->getName(),'methods'=>$route->methods(),'uri'=>$route->uri(),'action'=>$route->getActionName(),'middleware'=>$middleware];
        }
        $extensions=get_loaded_extensions();sort($extensions);
        return ['format'=>1,'kind'=>'schema-only','generated_at'=>gmdate('c'),'php_version'=>PHP_VERSION,'laravel_version'=>$app->version(),
            'database_engine'=>(string)$connection->selectOne('SELECT VERSION() AS version')->version,'extensions'=>$extensions,
            'tables'=>$tables,'routes'=>$routes];
    }
}
if(PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{
        $project=realpath($argv[1]??getcwd());
        if(!$project||!is_file($project.'/vendor/autoload.php'))throw new RuntimeException('Pass the application directory.');
        require $project.'/vendor/autoload.php';
        $app=require $project.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        set_exception_handler(function(Throwable $error){fwrite(STDERR,"Desktop schema inspection failed.\n");exit(1);});
        echo json_encode(FasakhanstaDesktopSchemaInspection::report($app),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable $error){fwrite(STDERR,"Desktop schema inspection failed.\n");exit(1);}
}
