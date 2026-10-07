<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Schema, Validator};

/** Read-only restaurant overview. Live drawer figures are restricted to the primary owner. */
class HomeOverview
{
    private TakeawayAccess $access;
    private array $columns = [];
    private const CHANNELS = ['app', 'takeaway', 'phone', 'dine'];
    public function __construct(TakeawayAccess $access) { $this->access = $access; }
    private function has(string $table, array $columns): bool
    {
        if (!isset($this->columns[$table])) $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        return !array_diff($columns, $this->columns[$table]);
    }
    private function range(array $values): array
    {
        $v = Validator::make($values, ['branch'=>'nullable|string|max:40', 'period'=>'nullable|in:today,week,month,custom',
            'from'=>'required_if:period,custom|nullable|date_format:Y-m-d', 'to'=>'required_if:period,custom|nullable|date_format:Y-m-d|after_or_equal:from'])->validate();
        $period = $v['period'] ?? 'today'; $end = now('Africa/Cairo')->startOfDay(); $start = $end->copy();
        if ($period === 'week') $start->subDays(6);
        elseif ($period === 'month') $start->startOfMonth();
        elseif ($period === 'custom') { $start = Carbon::parse($v['from'], 'Africa/Cairo'); $end = Carbon::parse($v['to'], 'Africa/Cairo'); }
        abort_if($start->diffInDays($end) > 365 || $end->gt(now('Africa/Cairo')->startOfDay()), 422, __('home_overview.invalid_range'));
        return ['branch'=>$v['branch'] ?? '', 'period'=>$period, 'from'=>$start->toDateString(), 'to'=>$end->toDateString()];
    }
    private function emptySales(): array
    {
        return ['count'=>0, 'gross_cents'=>0, 'delivery_cents'=>0, 'expenses_cents'=>0, 'net_cents'=>0,
            'channels'=>array_fill_keys(self::CHANNELS, ['count'=>0, 'gross_cents'=>0, 'delivery_cents'=>0])];
    }
    public function data(array $values, $actor): array
    {
        $actor = $this->access->actor($actor);
        $all = array_values(array_filter($this->access->branches($actor), fn($b)=>$b['kind'] === 'f'));
        foreach($all as &$entry)$entry['display_group']=$this->displayGroup($entry['name']);unset($entry);
        usort($all,fn($a,$b)=>[$a['display_group']==='store'?1:0,$a['id']]<=>[$b['display_group']==='store'?1:0,$b['id']]);
        $filters = $this->range($values); $selected = $all;
        if ($filters['branch'] !== '') { $branch = $this->access->branch($filters['branch'], $actor); abort_unless($branch['kind'] === 'f', 404); $branch['display_group']=$this->displayGroup($branch['name']); $selected = [$branch]; }
        $centralAccount = $actor->account_type === 'admin' && empty($actor->owner_resturant_id);
        $owner = (int)$actor->id === 1 && $centralAccount;
        // Order-management grants do not grant central admins access to sales amounts.
        $finance = $centralAccount ? $owner : $this->access->permissions($actor)['can_manage'];
        $central = $centralAccount && $filters['branch'] === '';
        $keys = array_column($selected, 'value'); $ids = array_column($selected, 'id');
        $from = Carbon::parse($filters['from'], 'Africa/Cairo'); $until = Carbon::parse($filters['to'], 'Africa/Cairo')->addDay();
        $previousFrom = $from->copy()->subDays($from->diffInDays($until));
        $modules = ['app'=>$this->has('orders', ['resturant_id','type','status','created_at','updated_at']),
            'pos'=>$this->has('takeaway_orders', ['branch','business_date','channel','total_cents','delivery_cents','created_at']),
            'expenses'=>$this->has('branch_expenses', ['branch','occurred_on','status','amount_cents']),
            'inventory'=>$this->has('branch_inventory', ['branch','ingredient_id','quantity_units']) && $this->has('stock_ingredients', ['name','unit','position'])];
        $branches = []; $restaurantRows = DB::table('resturants')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($selected as $b) {
            $r = $restaurantRows[$b['id']]; $state = $r->status ?? null;
            $branches[$b['value']] = $b + ['open'=>$state === null ? null : ($state === 'opened' && ($r->control ?? 'show') !== 'hide'),
                'active'=>['new'=>0,'preparing'=>0,'courier'=>0,'awaiting_payment'=>0], 'sales'=>$this->emptySales()];
        }
        $sales = $this->emptySales(); $previous = $this->emptySales(); $trend = $this->trend($from, $until);
        if ($modules['pos']) {
            DB::table('takeaway_orders')->whereIn('branch', $keys)->where('business_date', '>=', $previousFrom->toDateString())
                ->where('business_date', '<', $until->toDateString())->orderBy('id')->chunkById(500, function($rows) use (&$sales,&$previous,&$branches,&$trend,$from,$until) {
                    foreach ($rows as $r) {
                        $when = Carbon::parse($r->created_at, 'UTC')->setTimezone('Africa/Cairo');
                        // business_date is the POS accounting date; the hour comes from the frozen receipt timestamp.
                        $when->setDateFrom(Carbon::parse($r->business_date, 'Africa/Cairo'));
                        $channel = in_array($r->channel, ['phone','dine'], true) ? $r->channel : 'takeaway';
                        if ($r->business_date < $from->toDateString()) $this->add($previous, $channel, (int)$r->total_cents, (int)$r->delivery_cents);
                        else { $this->add($sales, $channel, (int)$r->total_cents, (int)$r->delivery_cents); $this->add($branches[$r->branch]['sales'], $channel, (int)$r->total_cents, (int)$r->delivery_cents); $this->point($trend,$when,(int)$r->total_cents); }
                    }
                });
        }
        $appFallback = 0;
        if ($modules['app']) {
            $q = $this->appQuery($ids)->where('orders.status','completed');
            $clock = $this->has('order_board_clocks', ['source','order_id','closed_at']);
            $saleClock = $this->has('branch_recipe_sales', ['branch','source_type','source_id','created_at']);
            if ($clock) $q->leftJoin('order_board_clocks as clock', function($j){$j->on('clock.order_id','=','orders.id')->where('clock.source','legacy');});
            if ($saleClock) {
                $branchExpression = DB::connection()->getDriverName() === 'sqlite' ? "'f:' || orders.resturant_id" : "CONCAT('f:', orders.resturant_id)";
                $q->leftJoin('branch_recipe_sales as stock_sale', function($j) use($branchExpression){$j->on('stock_sale.source_id','=','orders.id')->where('stock_sale.source_type','app')->on('stock_sale.branch','=',DB::raw($branchExpression));});
            }
            $clockExpr = $clock && $saleClock ? 'COALESCE(clock.closed_at, stock_sale.created_at)' : ($clock ? 'clock.closed_at' : ($saleClock ? 'stock_sale.created_at' : 'NULL'));
            $q->select('orders.*')->selectRaw($clockExpr.' AS completed_utc')->where(function($w) use($clockExpr,$previousFrom,$until) {
                $w->where(function($dated) use($clockExpr,$previousFrom,$until){$dated->whereRaw($clockExpr.' >= ?',[$previousFrom->copy()->utc()->toDateTimeString()])->whereRaw($clockExpr.' < ?',[$until->copy()->utc()->toDateTimeString()]);})
                    ->orWhere(function($old) use($clockExpr,$previousFrom,$until){$old->whereRaw($clockExpr.' IS NULL')->where('orders.updated_at','>=',$previousFrom->toDateTimeString())->where('orders.updated_at','<',$until->toDateTimeString());});
            });
            $bps = $this->serviceRate();
            $q->orderBy('orders.id')->chunkById(500, function($rows) use(&$sales,&$previous,&$branches,&$trend,&$appFallback,$from,$bps) {
                $carts = $this->has('carts',['order_id','price','qty']) ? DB::table('carts')->whereIn('order_id',$rows->pluck('id'))->get()->groupBy('order_id') : collect();
                foreach ($rows as $r) {
                    $when = Carbon::parse($r->completed_utc ?: $r->updated_at, $r->completed_utc ? 'UTC' : config('app.timezone'))->setTimezone('Africa/Cairo');
                    [$total,$delivery] = $this->appAmount($r,$carts[$r->id] ?? collect(),$bps);
                    if ($when->lt($from)) $this->add($previous,'app',$total,$delivery);
                    else { if (!$r->completed_utc) $appFallback++; $this->add($sales,'app',$total,$delivery); $this->add($branches['f:'.$r->resturant_id]['sales'],'app',$total,$delivery); $this->point($trend,$when,$total); }
                }
            }, 'orders.id', 'id');
        }
        if ($modules['expenses']) {
            foreach (DB::table('branch_expenses')->whereIn('branch',$keys)->where('status','approved')->where('occurred_on','>=',$previousFrom->toDateString())->where('occurred_on','<',$until->toDateString())->select('branch','occurred_on')->selectRaw('SUM(amount_cents) AS amount')->groupBy('branch','occurred_on')->get() as $r) {
                if ($r->occurred_on < $filters['from']) $previous['expenses_cents'] += (int)$r->amount;
                else { $sales['expenses_cents'] += (int)$r->amount; $branches[$r->branch]['sales']['expenses_cents'] += (int)$r->amount; }
            }
        }
        $this->net($sales); $this->net($previous); foreach($branches as &$b) $this->net($b['sales']); unset($b);
        $active = ['new'=>0,'preparing'=>0,'courier'=>0,'awaiting_payment'=>0]; $appOrders = 0; $cancelled = 0; $late = 0;
        if ($modules['app']) {
            $periodOrders = $this->appQuery($ids)->where('orders.created_at','>=',$from->toDateTimeString())->where('orders.created_at','<',$until->toDateTimeString());
            $appOrders = (clone $periodOrders)->whereNotNull('status')->count(); $cancelled = (clone $periodOrders)->whereIn('status',['cancelled','declined','rejected'])->count();
            $this->appQuery($ids)->whereIn('status',['pending','new_order','another_delegate','accepted','shipped'])->orderBy('id')->chunkById(500,function($rows) use(&$active,&$branches,&$late){foreach($rows as $r){$g=$this->appGroup($r);$active[$g]++;$branches['f:'.$r->resturant_id]['active'][$g]++;if(Carbon::parse($r->created_at,config('app.timezone'))->lt(now()->subMinutes(90)))$late++;}});
        }
        if ($this->has('pos_service_tickets',['branch','status','payment_status','business_date'])) {
            $q=DB::table('pos_service_tickets')->whereIn('branch',$keys);
            $cancelled+=(clone $q)->where('status','cancelled')->whereBetween('business_date',[$filters['from'],$filters['to']])->count();
            foreach((clone $q)->where('payment_status','unpaid')->where('status','!=','cancelled')->select('branch','status')->selectRaw('COUNT(*) AS n')->groupBy('branch','status')->get() as $r){$g=in_array($r->status,['new','open'],true)?'new':($r->status==='out_for_delivery'?'courier':(in_array($r->status,['awaiting_bill','finished'],true)?'awaiting_payment':'preparing'));$active[$g]+=(int)$r->n;$branches[$r->branch]['active'][$g]+=(int)$r->n;}
        }
        $inventory=$this->inventory($keys,$modules['inventory'],$centralAccount); $alerts=[];
        foreach(array_slice($inventory['attention'],0,5) as $i) $alerts[]=['kind'=>$i['quantity_units']<0?'negative_stock':'empty_stock','tone'=>$i['quantity_units']<0?'red':'amber','name'=>$i['name'],'branch'=>$branches[$i['branch']]['name'],'value'=>$i['quantity'],'unit'=>$i['unit'],'url'=>route('branch-stock.index',['branch'=>$i['branch']])];
        if($late) $alerts[]=['kind'=>'late_orders','tone'=>'red','value'=>$late,'url'=>route('orders.applies',$filters['branch']?['branch'=>$filters['branch']]:[])];
        if($modules['expenses']) {$n=DB::table('branch_expenses')->whereIn('branch',$keys)->where('status','pending')->count();if($n)$alerts[]=['kind'=>'pending_expenses','tone'=>'amber','value'=>$n,'url'=>route('branch-expenses.index',$filters['branch']?['branch'=>$filters['branch']]:[])];}
        if($this->has('pos_branch_print_jobs',['branch','status','created_at'])) {$n=DB::table('pos_branch_print_jobs')->whereIn('branch',$keys)->whereIn('status',['pending','claimed'])->where('created_at','<',now('UTC')->subMinutes(5))->count();if($n)$alerts[]=['kind'=>'print_pending','tone'=>'amber','value'=>$n,'url'=>route('print-settings.index')];}
        if($modules['inventory'] && $inventory['unconfigured_recipes'])$alerts[]=['kind'=>'missing_recipes','tone'=>'blue','value'=>$inventory['unconfigured_recipes'],'url'=>route('branch-stock.index',$filters['branch']?['branch'=>$filters['branch']]:[])];
        foreach($modules as $key=>$ready)if(!$ready)$alerts[]=['kind'=>'unavailable_'.$key,'tone'=>'amber','value'=>null,'url'=>null];
        $customers=$this->customers($ids,$central,$from,$until,$modules['app']);
        $recent=$this->recent($ids,$keys,$from,$until,$finance,$modules);
        $ranking=array_values($branches);usort($ranking,fn($a,$b)=>$finance?$b['sales']['gross_cents']<=>$a['sales']['gross_cents']:$b['sales']['count']<=>$a['sales']['count']);
        $ranked=array_map(fn($b)=>['branch'=>$b['value'],'name'=>$b['name'],'count'=>$b['sales']['count'],'amount_cents'=>$finance?$b['sales']['gross_cents']:null],array_slice($ranking,0,$centralAccount?10:5));
        if($centralAccount){foreach($ranked as $i=>&$rank)$rank['sales_rank']=$i+1;unset($rank);usort($ranked,fn($a,$b)=>[$this->displayGroup($a['name'])==='store'?1:0,$a['sales_rank']]<=>[$this->displayGroup($b['name'])==='store'?1:0,$b['sales_rank']]);}
        $completed=$sales['count'];$channels=[];foreach($sales['channels'] as $key=>$c)$channels[]=['key'=>$key,'count'=>$c['count'],'amount_cents'=>$finance?$c['gross_cents']:null];
        // Central admins receive counts and approved expenses, never sales or drawer amounts.
        foreach($branches as &$b){
            $b['completed']=$b['sales']['count'];
            if($centralAccount){
                $b['order_counts']=array_map(fn($c)=>$c['count'],$b['sales']['channels']);
                $b['expenses_cents']=$b['sales']['expenses_cents'];
            }
            if(!$finance)unset($b['sales']);
        } unset($b);
        foreach($trend['points'] as &$point)if(!$finance)unset($point['amount_cents']);unset($point);
        $drawer=$centralAccount?['owner_platform'=>$this->platformCounts(),'expenses_cents'=>$sales['expenses_cents']]:[];
        if($owner){
            $ready=$modules['app']&&$modules['pos']&&$modules['expenses']&&$this->has('carts',['order_id','price','qty'])&&$this->has('settings',['name','payload'])&&$this->has('branch_shift_closings',['snapshot','sequence','expected_cents'])&&$this->has('branch_shift_sources',['source','source_id'])&&$this->has('branch_expense_commands',['snapshot','branch'])&&$this->access->ready();
            $amounts=$ready?app(BranchShiftClosing::class)->ownerBalances($keys,$actor):[];
            $drawer['owner_drawer']=['ready'=>$ready,'total_cents'=>$ready?array_sum(array_column($amounts,'expected_cents')):null,'branches'=>$amounts];
        }
        return ['success'=>true,'updated_at'=>now('Africa/Cairo')->toIso8601String(),'filters'=>$filters,'branches'=>$all,'can_view_financials'=>$finance,
            'branch_home'=>!$centralAccount,'today_expenses'=>!$centralAccount?$this->todayExpenses($keys,$modules['expenses']):null,
            'modules'=>$modules,'sales'=>$finance?$sales:null,'previous'=>$finance?$previous:null,'completed'=>$completed,'cancelled'=>$cancelled,
            'active'=>$active,'app_orders'=>$appOrders,'customers'=>$customers,'open_branches'=>count(array_filter($branches,fn($b)=>$b['open']===true)),
            'selected_branches'=>count($selected),'branch_cards'=>array_values($branches),'ranking'=>$ranked,'channels'=>$channels,'trend'=>array_values($trend['points']),
            'inventory'=>$inventory,'alerts'=>$alerts,'recent'=>$recent,'legacy_app_dates'=>$appFallback]+$drawer;
    }
    private function todayExpenses(array $keys,bool $ready): array
    {
        $today=now('Africa/Cairo')->toDateString();$items=[];$total=0;
        if($ready){
            $names=app(ExpenseCategories::class)->options();
            foreach(DB::table('branch_expenses')->whereIn('branch',$keys)->where('occurred_on',$today)->where('status','approved')->select('category')->selectRaw('SUM(amount_cents) AS amount')->groupBy('category')->orderBy('category')->get() as $row){
                $amount=(int)$row->amount;$total+=$amount;$items[]=['name'=>$names[$row->category]??$row->category,'amount_cents'=>$amount];
            }
        }
        return ['date'=>$today,'total_cents'=>$ready?$total:null,'items'=>$items];
    }
    // Legacy restaurant records have no branch/store type. Use their explicit
    // store label for presentation only; never change ownership or sales scope.
    private function displayGroup(string $name): string
    {
        return preg_match('/(?<![\p{L}\p{N}])(?:[اأإ]?ستور|متجر|stores?)(?![\p{L}\p{N}])/iu',$name)?'store':'branch';
    }
    private function appQuery(array $ids) { return DB::table('orders')->whereIn('orders.resturant_id',$ids)->where('orders.type','current'); }
    private function appGroup(object $r): string
    {
        if($r->status==='shipped'||($r->status==='accepted'&&($r->delegate_from_out??'')==='in_resturant'))return 'courier';
        return $r->status==='accepted'||($r->accepted_notify??'')==='yes'?'preparing':'new';
    }
    private function add(array &$sum,string $channel,int $gross,int $delivery): void
    {
        $sum['count']++;$sum['gross_cents']+=$gross;$sum['delivery_cents']+=$delivery;$sum['channels'][$channel]['count']++;$sum['channels'][$channel]['gross_cents']+=$gross;$sum['channels'][$channel]['delivery_cents']+=$delivery;
    }
    private function net(array &$sum): void {$sum['net_cents']=$sum['gross_cents']-$sum['delivery_cents']-$sum['expenses_cents'];}
    private function serviceRate(): int
    {
        if(!$this->has('settings',['name','payload']))return 0;$value=DB::table('settings')->where('name','service_fees')->value('payload');$value=$value===null?0:json_decode($value,true);
        try{return Money::rate(is_scalar($value)?(string)$value:'0');}catch(\InvalidArgumentException $e){abort(503,__('home_overview.amount_error'));}
    }
    private function money($value): int {try{return Money::minor($value??0);}catch(\InvalidArgumentException $e){abort(503,__('home_overview.amount_error'));}}
    private function appAmount(object $r,$lines,int $bps): array
    {
        $sub=0;foreach($lines as $line){if(!empty($line->updated_total)){$sub+=$this->money($line->updated_total);continue;}$qty=(string)$line->qty;abort_unless(preg_match('/^\d{1,7}(?:\.\d{1,3})?$/D',$qty),503,__('home_overview.amount_error'));$p=explode('.',$qty);$millis=(int)$p[0]*1000+(int)str_pad($p[1]??'',3,'0');$sub+=intdiv($this->money($line->price)*$millis+500,1000);}
        if(!$lines->count())$sub=$this->money($r->total_price??0);$delivery=$this->money($r->delivery_price??0);$total=$sub+$delivery+$this->money($r->user_tax??0)+Money::commission($sub,$bps);
        abort_if($sub<0||$delivery<0||$total<0,503,__('home_overview.amount_error'));return [$total,$delivery];
    }
    private function trend(Carbon $from,Carbon $until): array
    {
        $days=$from->diffInDays($until);$mode=$days===1?'hour':($days>62?'month':'day');$points=[];$at=$from->copy();
        while($at->lt($until)){$key=$at->format($mode==='hour'?'Y-m-d H':($mode==='month'?'Y-m':'Y-m-d'));$points[$key]=['key'=>$key,'label'=>$at->format($mode==='hour'?'H:00':($mode==='month'?'m/Y':'d/m')),'count'=>0,'amount_cents'=>0];if($mode==='hour')$at->addHour();elseif($mode==='month')$at->startOfMonth()->addMonth();else $at->addDay();}
        return ['mode'=>$mode,'points'=>$points];
    }
    private function point(array &$trend,Carbon $when,int $gross): void
    {
        $key=$when->format($trend['mode']==='hour'?'Y-m-d H':($trend['mode']==='month'?'Y-m':'Y-m-d'));if(isset($trend['points'][$key])){$trend['points'][$key]['count']++;$trend['points'][$key]['amount_cents']+=$gross;}
    }
    private function inventory(array $keys,bool $ready,bool $byBranch=false): array
    {
        if(!$ready)return ['items'=>[],'attention'=>[],'tracked'=>0,'unconfigured_recipes'=>0];
        $stock=DB::table('branch_inventory')->whereIn('branch',$keys)->get()->groupBy('ingredient_id');$items=[];$attention=[];
        foreach(DB::table('stock_ingredients')->orderBy('position')->get() as $i){$rows=$stock[$i->id]??collect();$units=(int)$rows->sum('quantity_units');$items[]=['id'=>(int)$i->id,'name'=>$i->name,'unit'=>$i->unit,'quantity'=>app(BranchInventory::class)->quantity($units),'quantity_units'=>$units,'tracked_branches'=>$rows->count(),'negative_branches'=>$rows->where('quantity_units','<',0)->count()];foreach($rows as $r)if($r->quantity_units<=0)$attention[]=['name'=>$i->name,'branch'=>$r->branch,'unit'=>$i->unit,'quantity'=>app(BranchInventory::class)->quantity((int)$r->quantity_units),'quantity_units'=>(int)$r->quantity_units];}
        if($byBranch)foreach($items as &$item){
            // Null means no opening/receipt has been recorded, not zero stock.
            $balances=array_fill_keys($keys,null);
            foreach($stock[$item['id']]??collect() as $row)$balances[$row->branch]=['quantity'=>app(BranchInventory::class)->quantity((int)$row->quantity_units),'quantity_units'=>(int)$row->quantity_units];
            $item['balances']=$balances;
        }unset($item);
        usort($attention,fn($a,$b)=>$a['quantity_units']<=>$b['quantity_units']);$missing=0;
        if($this->has('branch_stock_recipes',['branch','product_id'])&&$this->has('resturant_products',['resturant_id','status'])){foreach($keys as $key){$id=(int)substr($key,2);$missing+=DB::table('resturant_products')->where('resturant_id',$id)->where('status','show')->whereNotIn('id',DB::table('branch_stock_recipes')->where('branch',$key)->select('product_id'))->count();}}
        return ['items'=>$items,'attention'=>$attention,'tracked'=>count(array_filter($items,fn($i)=>$i['tracked_branches']>0)),'unconfigured_recipes'=>$missing];
    }
    /** Current platform totals for the primary owner only; independent of branch/date filters. */
    private function platformCounts(): array
    {
        $out=['go_partners'=>null,'go_stores'=>null,'go_users'=>null,'fasakhansta_users'=>null,
            'fasakhansta_stores'=>DB::table('resturants')->count(),'pending_stores'=>null,'pending_partners'=>null,'pending_total'=>null];
        if($this->has('users',['account_type','app_scope'])){
            $users=DB::table('users')->where('account_type','user');
            $out['go_users']=(clone $users)->whereIn('app_scope',['go','go_customer','go_drive'])->count();
            $out['fasakhansta_users']=(clone $users)->where(function($q){$q->whereNull('app_scope')->orWhereIn('app_scope',['','fasakhansta']);})->count();
        }
        $applications=$this->has('pending_vendors',['id','type','status','profession_key']);
        if($applications){
            $pending=DB::table('pending_vendors')->where('status','pending')->where(function($q){$q->whereIn('type',['vendor','delegate'])->orWhere('profession_key','store_owner');});
            $out['pending_total']=(clone $pending)->count();
            $out['pending_stores']=(clone $pending)->where(function($q){$q->where('type','vendor')->orWhere('profession_key','store_owner');})->count();
            $out['pending_partners']=$out['pending_total']-$out['pending_stores'];
        }
        if($applications&&$this->has('users',['account_type','app_scope','status','pending_vendor_id'])){
            $storeApplications=DB::table('pending_vendors')->where('profession_key','store_owner')->select('id');
            $accounts=DB::table('users')->where('app_scope','go_partner')->where('status','accepted');
            $out['go_partners']=(clone $accounts)->where('account_type','delegate')->where(function($q) use($storeApplications){$q->whereNull('pending_vendor_id')->orWhereNotIn('pending_vendor_id',$storeApplications);})->count();
            if($this->has('go_stores',['user_id']))$out['go_stores']=(clone $accounts)
                ->where(function($q) use($storeApplications){$q->where('account_type','vendor')->orWhere(function($q) use($storeApplications){$q->where('account_type','delegate')->whereIn('pending_vendor_id',$storeApplications);});})
                ->whereIn('id',DB::table('go_stores')->select('user_id'))->count();
        }
        return $out;
    }
    private function customers(array $ids,bool $central,Carbon $from,Carbon $until,bool $app): array
    {
        $out=['global'=>$central,'total'=>0,'period'=>0,'delegates'=>null,'pending_partners'=>null];
        if($central){$q=DB::table('users')->where('account_type','user');if($this->has('users',['app_scope']))$q->where(function($w){$w->whereNull('app_scope')->orWhereNotIn('app_scope',['go','go_customer','go_partner','go_drive']);});$out['total']=(clone $q)->count();$out['period']=(clone $q)->where('created_at','>=',$from->toDateTimeString())->where('created_at','<',$until->toDateTimeString())->count();
            $d=DB::table('users')->where('account_type','delegate');if($this->has('users',['app_scope']))$d->where(function($w){$w->whereNull('app_scope')->orWhereNotIn('app_scope',['go','go_customer','go_partner','go_drive']);});$out['delegates']=$d->count();if($this->has('pending_vendors',['status']))$out['pending_partners']=DB::table('pending_vendors')->where('status','pending')->count();
        }elseif($app&&$this->has('orders',['user_id'])){$q=$this->appQuery($ids)->whereNotNull('status')->whereNotNull('user_id');$out['total']=(clone $q)->distinct()->count('user_id');$out['period']=(clone $q)->where('created_at','>=',$from->toDateTimeString())->where('created_at','<',$until->toDateTimeString())->distinct()->count('user_id');}
        return $out;
    }
    private function recent(array $ids,array $keys,Carbon $from,Carbon $until,bool $finance,array $modules): array
    {
        $out=[];
        if($modules['app'])foreach($this->appQuery($ids)->whereNotNull('status')->where('created_at','>=',$from->toDateTimeString())->where('created_at','<',$until->toDateTimeString())->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get() as $r){$state=$r->status==='completed'?'completed':(in_array($r->status,['cancelled','declined','rejected'],true)?'cancelled':$this->appGroup($r));$out[]=['id'=>(int)$r->id,'number'=>$r->order_no??'#'.$r->id,'channel'=>'app','branch'=>'f:'.$r->resturant_id,'status'=>$state,'at'=>Carbon::parse($r->created_at,config('app.timezone'))->toIso8601String(),'url'=>route('order-board.details',['legacy',$r->id])];}
        if($modules['pos'])foreach(DB::table('takeaway_orders')->whereIn('branch',$keys)->where('business_date','>=',$from->toDateString())->where('business_date','<',$until->toDateString())->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get() as $r){$out[]=['id'=>(int)$r->id,'number'=>'POS-'.str_pad($r->id,6,'0',STR_PAD_LEFT),'channel'=>$r->channel,'branch'=>$r->branch,'status'=>'completed','at'=>Carbon::parse($r->created_at,'UTC')->toIso8601String(),'url'=>route('takeaway.details',['id'=>$r->id])];}
        usort($out,fn($a,$b)=>strcmp(Carbon::parse($b['at'])->utc()->toDateTimeString(),Carbon::parse($a['at'])->utc()->toDateTimeString()));return array_slice($out,0,8);
    }
}

