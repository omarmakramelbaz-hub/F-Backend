#!/usr/bin/env python3
"""Read-only inventory bounds, verified-area identities and sensitive-field exclusion."""
import json
import os
import pathlib
import pwd
import shutil
import subprocess
import tempfile
import unittest

SOURCE=pathlib.Path(__file__).absolute().parent.parent/'deployment/whatsapp_cairo_store_preflight.php'
PHP=os.environ.get('WHATSAPP_TEST_PHP')or shutil.which('php')
FIXTURE=r'''<?php
namespace Illuminate\Support\Facades {
 class Schema {
  public static function hasTable($name){return in_array($name,['resturants','areas'],true);}
  public static function getColumnListing($name){return $name==='areas'?['id','title_ar','parent_id']:
   ['id','name','parent_id','type','status','area_id','city_id','open_at','close_at','lat','lng','km_price','default_0_1','default_1_2','default_2_3','resturant_phone','email','api_key','user_id'];}
 }
 class DB {
  public static function table($name){if(!in_array($name,['resturants','areas'],true))throw new \RuntimeException('UNEXPECTED_PRIVATE_QUERY');return new \FixtureQuery($name);}
  public static function __callStatic($name,$args){throw new \RuntimeException('UNEXPECTED_WRITE');}
 }
}
namespace App\Models {class User{public static function withoutGlobalScopes(){return new self;}public function find($id){return(object)['id'=>1];}}}
namespace App\Services\Dashboard {
 class WhatsAppInboxAccess{public function actor($a){return $a;}}
 class TakeawayAccess{public function permissions($a){return['can_checkout'=>true];}}
}
namespace {
 require getcwd().'/app/Models/Resturant.php';require getcwd().'/app/Models/Area.php';
 class FixtureQuery {
  private $table;private $limit=999;private $ids=[];private $where=[];
  public function __construct($table){$this->table=$table;}
  public function select($columns){foreach(['resturant_phone','email','api_key','user_id']as$private)if(in_array($private,$columns,true))throw new \RuntimeException('PRIVATE_FIELD_SELECTED');return $this;}
  public function orderBy($v){return $this;}public function limit($v){$this->limit=$v;return $this;}
  public function whereIn($key,$ids){$this->ids=$ids;return $this;}
  public function where($key,$value){$this->where[$key]=$value;return $this;}
  public function count(){return count($this->rows());}
  public function get(){return array_slice($this->rows(),0,$this->limit);}
  private function rows(){
   if($this->table==='areas')return array_values(array_filter([(object)['id'=>10,'title_ar'=>'مدينة عامة','parent_id'=>20],(object)['id'=>20,'title_ar'=>'منطقة عامة','parent_id'=>null]],fn($a)=>in_array($a->id,$this->ids)));
   $rows=[];for($i=1;$i<=(defined('SPECIAL_INVENTORY')?220:151);$i++)$rows[]=(object)['id'=>$i,'name'=>'Public store '.$i,'parent_id'=>(defined('SPECIAL_INVENTORY')&&($i<171||$i>206)?94:301),'type'=>'store','status'=>'opened','area_id'=>10,'city_id'=>77,'open_at'=>'18:00','close_at'=>'02:00','lat'=>'30.0444','lng'=>'31.2357','km_price'=>'50.00','default_0_1'=>'10.50','default_1_2'=>'20','default_2_3'=>'PRIVATE_INVALID_TIER_SENTINEL','resturant_phone'=>'PRIVATE_STAFF_PHONE_SENTINEL','email'=>'PRIVATE_EMAIL_SENTINEL','api_key'=>'PRIVATE_PROVIDER_KEY_SENTINEL','user_id'=>999];
   if(defined('SPECIAL_INVENTORY'))$rows[]=(object)['id'=>301,'name'=>'Fulfillment branch','parent_id'=>null,'type'=>'branch','status'=>'opened'];
   return array_values(array_filter($rows,function($r){foreach($this->where as$k=>$v)if(($r->$k??null)!=$v)return false;return true;}));
  }
 }
 class FixtureKernel{public function bootstrap(){}}
 class FixtureApp{public function make($class){return new FixtureKernel;}}
 function app($class){return new $class;}
 function config($key,$default=null){return[
  'whatsapp_orders'=>['mode'=>'review','enabled'=>true,'automation_actor_id'=>1,'allowed_branch_ids'=>['94','301','307','363'],'activation_message_id'=>3100,'activation_event_id'=>4095,'api_key'=>'PRIVATE_CONFIGURATION_KEY_SENTINEL'],
  'whatsapp_cart'=>['activated_at'=>null],
  'services.maps.phone_open_enabled'=>true,
  'services.maps.browser_key'=>'PRIVATE_MAP_KEY_SENTINEL',
  'services.maps.osrm_url'=>'https://road.example/PRIVATE_ROAD_PROVIDER_SENTINEL',
  'services.maps.photon_url'=>'http://search.example/PRIVATE_SEARCH_PROVIDER_SENTINEL'
  ][$key]??$default;}
}
'''


@unittest.skipUnless(PHP,'Set WHATSAPP_TEST_PHP for the isolated PHP inventory test')
class StorePreflightTest(unittest.TestCase):
    def run_fixture(self,verified=True,special=False):
        with tempfile.TemporaryDirectory()as folder:
            root=pathlib.Path(folder)
            for relative in('vendor','bootstrap','app/Models'):(root/relative).mkdir(parents=True,exist_ok=True)
            fixture=FIXTURE.replace('namespace {',"namespace { define('SPECIAL_INVENTORY',true);",1)if special else FIXTURE
            (root/'vendor/autoload.php').write_text(fixture)
            (root/'bootstrap/app.php').write_text('<?php return new FixtureApp;')
            method="return $this->belongsTo(Area::class,'area_id');"if verified else"return null;"
            (root/'app/Models/Resturant.php').write_text(r'<?php namespace App\Models;class Resturant{public function area(){'+method+'}}')
            (root/'app/Models/Area.php').write_text(r"<?php namespace App\Models;class Area{public function parent(){return $this->belongsTo(Area::class,'parent_id');}}")
            content=SOURCE.read_text();marker="chdir('/home/fasakha/public_html')";self.assertEqual(content.count(marker),1)
            content=content.replace(marker,'chdir('+json.dumps(str(root))+')')
            # The local PHP build lacks POSIX; only the fixture copy substitutes identity.
            content=content.replace("||!function_exists('posix_getpwnam')||!function_exists('posix_geteuid')",'')
            content=content.replace("posix_getpwnam('fasakha')","['uid'=>"+str(os.geteuid())+"]")
            content=content.replace('posix_geteuid()',str(os.geteuid()))
            script=root/'inventory.php';script.write_text(content)
            result=subprocess.run([PHP,str(script)],capture_output=True,text=True,timeout=15)
            self.assertEqual(result.returncode,0,result.stderr)
            for secret in('PRIVATE_STAFF_PHONE_SENTINEL','PRIVATE_EMAIL_SENTINEL','PRIVATE_PROVIDER_KEY_SENTINEL','PRIVATE_CONFIGURATION_KEY_SENTINEL','PRIVATE_MAP_KEY_SENTINEL','PRIVATE_ROAD_PROVIDER_SENTINEL','PRIVATE_SEARCH_PROVIDER_SENTINEL','PRIVATE_INVALID_TIER_SENTINEL'):
                self.assertNotIn(secret,result.stdout)
            return json.loads(result.stdout)

    def test_bounded_public_inventory_has_no_private_values_or_coordinates(self):
        report=self.run_fixture()
        self.assertTrue(report['stores_truncated']);self.assertEqual(report['stores_reported'],150)
        for field in('database_writes','configuration_writes','network_requests','ai_calls','orders_created','customer_queries'):
            self.assertEqual(report[field],0)
        self.assertFalse(report['classification_applied']);self.assertEqual(report['configuration']['mode'],'review')
        self.assertEqual(report['maps'],{'phone_open_enabled':True,'road_provider_configured':True,'address_provider_configured':False,'browser_key_configured':True})
        self.assertEqual(report['stores_total'],151)
        store=report['stores'][0]
        self.assertEqual(store['delivery_tiers'],{'default_0_1':10.5,'default_1_2':20,'default_2_3':None})
        self.assertEqual(store['parent_id'],'301');self.assertEqual(store['type'],'store')
        self.assertTrue(store['pin_ready']);self.assertTrue(store['rate_configured']);self.assertTrue(store['working_hours']['overnight'])
        self.assertEqual(store['area_name'],'مدينة عامة');self.assertEqual(store['parent_area_name'],'منطقة عامة')
        self.assertFalse(report['public_schema']['city_relation_verified']);self.assertNotIn('city_name',store)
        for field in('lat','lng','latitude','longitude','km_price','resturant_phone','email','api_key','user_id'):
            self.assertNotIn(field,store);self.assertNotIn(field,report['public_schema']['resturants'])

    def test_unverified_area_relation_never_guesses_location_names(self):
        report=self.run_fixture(verified=False)
        self.assertFalse(report['public_schema']['area_relation_verified'])
        self.assertNotIn('area_name',report['stores'][0]);self.assertNotIn('parent_area_name',report['stores'][0])
        self.assertEqual(report['stores'][0]['area_id'],'10')

    def test_parent_301_children_are_prioritized_after_large_other_inventory(self):
        report=self.run_fixture(special=True)
        self.assertEqual(report['stores_total'],221)
        self.assertEqual(report['stores'][0]['id'],'301')
        self.assertEqual(report['parent_301']['children_total'],36)
        self.assertEqual(report['parent_301']['children_reported'],36)
        self.assertFalse(report['parent_301']['children_truncated'])
        self.assertEqual([r['id']for r in report['stores'][1:37]],[str(i)for i in range(171,207)])
        self.assertEqual(report['stores_reported'],150)
        self.assertTrue(report['stores_truncated'])
        for field in('delivery_tiers','pin_ready','rate_configured'):
            self.assertIn(field,report['stores'][0])


if __name__=='__main__':unittest.main()
