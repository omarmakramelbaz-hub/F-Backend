#!/usr/bin/env python3
"""Share customer directory reads and phone suggestions without granting other branches' write access."""
from bonus_owner_patch import install as install_files
import argparse
from pathlib import Path
import json


def patch_customers(source: str) -> str:
    marker = "// Shared customer directory reads for every authenticated branch."
    if marker in source:
        return source
    start = source.index('    public function listing(array $values,$actor): array')
    end = source.index('    public function directorySave(', start)
    method = source[start:end]
    begin = method.index("        $sources=DB::table('crm_customer_sources')->whereIn('branch',$scope);")
    finish = method.index("        $search=trim($v['search']??'');", begin)
    original_queries = method[begin:finish]
    method = method[:begin] + ("        // Shared customer directory reads for every authenticated branch.\n"
        "        $shared=($actor->account_type==='admin'&&empty($actor->owner_resturant_id))||(in_array($actor->account_type,['admin','vendor','resturant_owner'],true)&&count(array_filter($branches,fn($b)=>$b['kind']==='f'))>0);\n"
        "        if($shared){\n"
        "            $sources=DB::table('crm_customer_sources');\n"
        "            if($source!=='all')$sources->where('channel',$source);\n"
        "            $q=DB::table('crm_customers');\n"
        "            if($source!=='all')$q->whereIn('id',(clone $sources)->select('customer_id'));\n"
        "        }else{\n" + original_queries + "        }\n") + method[finish:]
    # Read source badges globally, but derive action scope from the actor's permitted branches.
    anchor = "$scope=array_column($branches,'value');"
    if method.count(anchor) != 1:
        raise ValueError('CUSTOMER_BRANCH_SCOPE_ANCHOR_CHANGED')
    method = method.replace(anchor, anchor + "$central=$actor->account_type==='admin'&&empty($actor->owner_resturant_id);if($central)$scope[]='';", 1)
    anchor = "DB::table('crm_customer_sources')->whereIn('branch',$scope)->whereIn('customer_id'"
    if method.count(anchor) != 1:
        raise ValueError('CUSTOMER_ASSOCIATIONS_ANCHOR_CHANGED')
    method = method.replace(anchor, "DB::table('crm_customer_sources')->when(!$shared,fn($q)=>$q->whereIn('branch',$scope))->whereIn('customer_id'", 1)
    anchor = 'function($row)use($associations){'
    if method.count(anchor) != 1:
        raise ValueError('CUSTOMER_MAP_ANCHOR_CHANGED')
    method = method.replace(anchor, 'function($row)use($associations,$scope,$central){', 1)
    anchor = "$item['branch']=$links->first(fn($link)=>$link->branch!=='')->branch??'';"
    if method.count(anchor) != 1:
        raise ValueError('CUSTOMER_ACTION_SCOPE_ANCHOR_CHANGED')
    method = method.replace(anchor, "$allowed=$links->filter(fn($link)=>in_array($link->branch,$scope,true));$item['branch']=$allowed->first(fn($link)=>$link->branch!=='')->branch??'';", 1)
    old = "$item['marketing_consent']=(bool)$row->marketing_consent;return $item;"
    new = "$item['marketing_consent']=(bool)$row->marketing_consent;$item['can_edit_profile']=$allowed->isNotEmpty()||($central&&$links->isEmpty());return $item;"
    if method.count(old) != 1:
        raise ValueError('CUSTOMER_RESULT_ANCHOR_CHANGED')
    method = method.replace(old, new, 1)
    return source[:start] + method + source[end:]


def patch_phone(source: str) -> str:
    marker = '// Shared directory lookup precedes branch-local historical suggestions.'
    if marker in source:
        return source
    start = source.index('    public function customers(array $values,$actor): array')
    end = source.index('    public static function normalizedColumn(', start)
    method = source[start:end]
    anchor = "        $scope=$central&&$branch['kind']==='f'?"
    if method.count(anchor) != 1:
        raise ValueError('PHONE_LOOKUP_ANCHOR_CHANGED')
    block = """        // Shared directory lookup precedes branch-local historical suggestions.
        if($branch['kind']==='f'&&in_array($actor->account_type,['admin','vendor','resturant_owner'],true)&&$this->access->has('crm_customers','phone_key')){
            $directory=DB::table('crm_customers');$this->numberFilter($directory,'phone_key',$keys,$prefix);
            foreach($directory->orderByRaw('CASE WHEN phone_key = ? THEN 0 ELSE 1 END',[BranchCustomers::phoneKey($v['phone'])])->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get() as $row){
                $add(['customer_id'=>null,'customer_revision'=>null,'directory_id'=>(int)$row->id,
                    'name'=>$row->name??'','phone'=>$row->phone,'address'=>$row->address??'','area'=>$row->area??'',
                    'delivery_notes'=>$row->delivery_notes??'','latitude'=>$row->latitude,'longitude'=>$row->longitude]);
            }
        }
"""
    return source[:start] + method.replace(anchor, block + anchor, 1) + source[end:]


def patch_customer_ui(source: str) -> str:
    marker = '/* shared-directory read access */'
    if marker in source:
        return source
    start = source.index('function renderCustomers(target){')
    end = source.index('function checkField(', start)
    method = source[start:end]
    old = "var a=actions();a.appendChild(btn('تعديل'"
    if method.count(old) != 1:
        raise ValueError('CUSTOMER_ACTIONS_ANCHOR_CHANGED')
    method = method.replace(old, "var a=actions();/* shared-directory read access */if(item.can_edit_profile!==false){a.appendChild(btn('تعديل'", 1)
    old = 'a.appendChild(options);'
    if method.count(old) != 1:
        raise ValueError('CUSTOMER_CONSENT_ANCHOR_CHANGED')
    method = method.replace(old, old + "}else a.appendChild(n('small','متاح لاختيار العميل في طلب دليفري','op-help'));", 1)
    return source[:start] + method + source[end:]


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--backup', required=True, type=Path)
    parser.add_argument('--php', required=True)
    args = parser.parse_args()
    if str(args.root) != '/home/fasakha/public_html' or args.root.resolve() != args.root:
        raise SystemExit('UNEXPECTED_APPLICATION_ROOT')
    try:
        specs = [('app/Services/Dashboard/BranchCustomers.php', patch_customers),
                 ('app/Services/Dashboard/PosServicePhone.php', patch_phone),
                 ('public/dashboard/js/customer-crm.js', patch_customer_ui)]
        print('GLOBAL_CUSTOMER_READ_PATCH=' + json.dumps(install_files(args.root, args.backup, args.php, specs)))
    except Exception as error:
        raise SystemExit('CUSTOMER_PATCH_FAILED ' + type(error).__name__)
