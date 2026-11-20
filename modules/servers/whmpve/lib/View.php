<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class View
{
    public static function clientTask(array $task): array
    {
        $result = Jobs::public($task);
        if ($result['error']) { $result['error'] = '任务失败，请联系支持，任务编号 #'.$task['id']; }
        return $result;
    }

    public static function state(int $service,bool $canPurchase=false,?array $permissions=null,bool $canManage=true): array
    {
        $admin=$permissions===null ? [] : ['admin_permissions'=>$permissions];
        $native = DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
            ->where('h.id',$service)->select('h.*','p.name as product_name','p.tax as product_tax')->first();
        $instance = DB::table('mod_whmpve_instances')->where('service_id',$service)->first();
        if (!$native || !$instance) {
            return ['service_id'=>$service,'lifecycle'=>'pending','service_status'=>$native->domainstatus ?? 'Pending',
                'hostname'=>$native->domain ?? '','product_name'=>$native->product_name ?? '云服务器',
                'spec'=>null,'tasks'=>[],'images'=>[],'network_mode'=>'none','can_manage'=>$canManage]+$admin;
        }
        $i = (array)$instance;
        $server = ServerConfig::get((int)$i['server_id']);
        $settings = Util::unpack($server['settings']);
        $spec = Util::unpack($i['applied'] ?: $i['desired']);
        $plan = Util::scoped('plans',(int)$i['plan_id'],(int)$i['server_id']);
        $image = Util::scoped('images',(int)$i['image_id'],(int)$i['server_id']);
        $tasks = DB::table('mod_whmpve_tasks')->where('service_id',$service)->orderByDesc('id')->limit(12)->get();
        $images = Catalog::images((int)$i['server_id'],$spec);
        $ips=self::instanceIps($i,$spec['network'] ?? []);
        $currency = (int)DB::table('tblclients')->where('id',$native->userid)->value('currency');
        $clientSpec=array_diff_key($spec,array_flip(['image_group_ids','ip_pool_ids','allowed_options','system_storage','network']));
        $clientSpec['data_disks']=array_map(fn($disk)=>array_intersect_key($disk,array_flip(['id','size_gib','mountpoint'])),$spec['data_disks'] ?? []);
        $traffic=Traffic::summary($service,$spec);
        $recurring=Purchases::available() ? Addons::recurring($service) : [];
        $addons=Util::enabled() ? Purchases::offers($i,$native,$spec,$traffic,$currency,$canPurchase) : [];
        $addons['purchased']=array_map(fn($addon)=>['name'=>$addon->name,'cycle'=>$addon->billingcycle,
            'price'=>(string)formatCurrency((float)$addon->recurring,$currency)],$recurring);
        $renewal=(float)$native->amount;
        foreach ($recurring as $addon) {
            if (empty($addon->in_base) && $addon->billingcycle===$native->billingcycle) { $renewal+=(float)$addon->recurring; }
        }
        if ($admin) {
            $admin+=['server_id'=>(int)$i['server_id'],'vmid'=>(int)$i['vmid'],'node'=>$i['node'],
                'can_edit_config'=>InstanceConfig::editable($i['lifecycle'],$native->domainstatus,$native->suspendreason)];
        }
        return [
            'service_id'=>$service,'hostname'=>$i['hostname'],'product_name'=>$native->product_name,'service_status'=>$native->domainstatus,
            'lifecycle'=>$i['lifecycle'],'kind'=>$i['kind'],'spec'=>$clientSpec,'plan_name'=>$plan['name'],
            'image_name'=>$image['name'],'image_id'=>$image['id'],'images'=>array_map(fn($r)=>array_intersect_key($r,array_flip(['id','name','kind','description'])),$images),
            'ips'=>$ips->all(),'network_mode'=>$ips->count() ? 'static' : 'none','server_name'=>$server['name'],
            'connection_display'=>self::connectionDisplay(Util::unpack($plan['spec'])['connection_display'] ?? [],$ips->pluck('address')->all()),
            'traffic'=>$traffic,'backups'=>Backups::summary($i),'addons'=>$addons,
            'tasks'=>$tasks->map(fn($r)=>$admin ? Jobs::public((array)$r)+['actor'=>$r->actor] : self::clientTask((array)$r))->all(),
            'billing'=>['amount'=>(string)formatCurrency($renewal,$currency),'cycle'=>$native->billingcycle,'next_due'=>$native->nextduedate],
            'module_enabled'=>Util::enabled(),'console_available'=>Util::enabled() && !empty($settings['console_url']) && !empty($settings['console_user']),
            'has_instance'=>!empty($i['vmid']),
            'can_manage'=>$canManage,
        ]+$admin;
    }

    private static function connectionDisplay(array $display,array $addresses): ?array
    {
        if (empty($display['private_address'])) { return null; }
        $parts=[4=>[],6=>[]];
        foreach ($addresses as $address) {
            $packed=inet_pton($address);
            if ($packed===false) { continue; }
            if (strlen($packed)===4) { $parts[4][]=explode('.',$address); }
            else { $parts[6][]=array_values(array_map('dechex',unpack('n*',$packed))); }
        }
        $note=preg_replace_callback('/\[(?:([1-9][0-9]*)_)?ipv([46])_([1-8])(?::0([1-9]))?\]/',static function($match) use($parts) {
            $index=(int)($match[1] ?: 1)-1;
            $value=$parts[(int)$match[2]][$index][(int)$match[3]-1] ?? null;
            return $value===null ? $match[0] : str_pad($value,(int)($match[4] ?? 0),'0',STR_PAD_LEFT);
        },$display['port_note'] ?? '');
        return ['public_ip'=>$display['public_ip'] ?? '','port_note'=>$note];
    }

    public static function instanceConfig(array $instance): array
    {
        $spec=Util::unpack($instance['applied'] ?: $instance['desired']);
        $ips=self::instanceIps($instance,$spec['network'] ?? []);
        $addresses=$ips->pluck('address')->all();
        return array_intersect_key($instance,array_flip(['kind','hostname','vmid','server_id']))+[
            'addresses'=>$addresses,'ips'=>$ips->all(),'config_revision'=>InstanceConfig::revision($instance,$addresses),'spec'=>$spec,
        ];
    }

    private static function instanceIps(array $instance,array $network)
    {
        return DB::table('mod_whmpve_ips')->where('server_id',$instance['server_id'])->where('service_id',$instance['service_id'])
            ->where('state','assigned')->orderBy('id')->get(['address','family','prefix','gateway'])->map(fn($row)=>[
                'address'=>$row->address,'prefix'=>$network['prefix'.$row->family] ?? $row->prefix,
                'gateway'=>$network['gateway'.$row->family] ?? $row->gateway,
            ]);
    }

    public static function metrics(int $service,string $timeframe='hour'): array
    {
        if (!in_array($timeframe,['hour','day','week','month'],true)) { throw new Fault('时间范围无效'); }
        $i = Jobs::instance($service);
        if (!$i['vmid'] || $i['lifecycle']==='terminated') { return ['status'=>'absent','history'=>[]]; }
        $since = (int)($i['metric_since'] ?: strtotime($i['created_at'].' UTC'));
        $key = 'metrics:'.$i['server_id'].':'.$i['uuid'].':'.$i['vmid'].':'.$since.':'.$timeframe;
        $cached = DB::table('mod_whmpve_cache')->where('cache_key',$key)->where('expires_at','>',time())->first();
        if ($cached) { return Util::unpack($cached->value); }
        $pve = new Pve((int)$i['server_id']);
        $owned = $pve->owned($i);
        if (!$owned) { return ['status'=>'missing','history'=>[]]; }
        $current = $pve->request('GET',$owned['path'].'/status/current');
        $rrd = $pve->request('GET',$owned['path'].'/rrddata',['timeframe'=>$timeframe,'cf'=>'AVERAGE']);
        $history = [];
        foreach ($rrd as $point) {
            if (!isset($point['time']) || (int)$point['time']<$since) { continue; }
            $history[] = [
                'time'=>$point['time'],'cpu'=>$point['cpu'] ?? null,'memory'=>$point['mem'] ?? null,'max_memory'=>$point['maxmem'] ?? null,
                'disk'=>$i['kind']==='lxc' ? ($point['disk'] ?? null) : null,'max_disk'=>$point['maxdisk'] ?? null,
                'netin'=>$point['netin'] ?? null,'netout'=>$point['netout'] ?? null,
                'diskread'=>$point['diskread'] ?? null,'diskwrite'=>$point['diskwrite'] ?? null,
                'pressure_cpu'=>$point['pressurecpusome'] ?? null,'pressure_io'=>$point['pressureiosome'] ?? null,
                'pressure_memory'=>$point['pressurememorysome'] ?? null,
            ];
        }
        $result = ['status'=>$current['status'] ?? 'unknown','cpu'=>$current['cpu'] ?? null,'memory'=>$current['mem'] ?? null,
            'max_memory'=>$current['maxmem'] ?? null,'disk'=>$i['kind']==='lxc' ? ($current['disk'] ?? null) : null,
            'load'=>$i['kind']==='qemu' && ($current['status'] ?? '')==='running' ? $pve->loadAverage($owned) : null,
            'max_disk'=>$current['maxdisk'] ?? null,'uptime'=>$current['uptime'] ?? null,'history'=>$history,'updated_at'=>time()];
        DB::table('mod_whmpve_instances')->where('id',$i['id'])->update(['power_state'=>$result['status'],'power_updated_at'=>Util::now()]);
        DB::table('mod_whmpve_cache')->updateOrInsert(['cache_key'=>$key],[
            'value'=>Util::json($result),'expires_at'=>time()+12,'created_at'=>Util::now(),'updated_at'=>Util::now(),
        ]);
        return $result;
    }

    public static function plans(int $server): array
    {
        ServerConfig::get($server);
        $used = Catalog::usedPlans($server);
        return DB::table('mod_whmpve_plans')->where('server_id',$server)->orderBy('name')->get()
            ->map(function($row) use ($used) {
                $r=(array)$row; $r['spec']=Util::unpack($r['spec']); $r['type_locked']=in_array((int)$r['id'],$used,true);
                unset($r['traffic_reset_prices']); return $r;
            })->all();
    }

    public static function task(int $id,int $server=0,int $service=0): array
    {
        $task = Util::row('tasks',$id);
        if ($server && (int)$task['server_id']!==$server) { throw new Fault('任务不属于所选服务器',404); }
        if ($service && (int)$task['service_id']!==$service) { throw new Fault('任务不属于当前服务',404); }
        $instance = Jobs::instance((int)$task['service_id']);
        return Jobs::public($task) + ['logs'=>Util::unpack($task['logs']),'attempts'=>$task['attempts'],'actor'=>$task['actor'],
            'can_retry'=>$task['status']==='failed' && ($instance['lifecycle']!=='terminated' || $task['action']==='backup_delete')];
    }

    public static function adminState(int $server,array $query,array $permissions): array
    {
        $servers = [];
        foreach (DB::table('mod_whmpve_servers')->orderBy('id')->pluck('id') as $id) {
            $row = ServerConfig::get((int)$id);
            $row['settings'] = Util::unpack($row['settings']);
            $row['has_console_password'] = !empty($row['settings']['console_password']);
            unset($row['settings']['console_password']);
            $row['instances'] = DB::table('mod_whmpve_instances')->where('server_id',$id)->where('lifecycle','!=','terminated')->count();
            $servers[] = $row;
        }
        if ($server) { ServerConfig::get($server); }
        $out = ['servers'=>$servers,'plans'=>$server ? self::plans($server) : [],'images'=>[],'ips'=>[],'ip_pools'=>[],'image_groups'=>[]];
        $out['currencies']=Purchases::available() ? Purchases::currencies() : [];
        if ($server) {
            foreach (['images','ips','ip_pools','image_groups'] as $table) {
                $rows=DB::table('mod_whmpve_'.$table)->where('server_id',$server);
                if ($table==='ips' && array_key_exists('pool_id',$query)) { $rows->where('pool_id',(int)$query['pool_id']); }
                $out[$table]=$rows->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
            }
            foreach ($out['ip_pools'] as &$pool) {
                $pool['total']=DB::table('mod_whmpve_ips')->where('pool_id',$pool['id'])->count();
                $pool['free']=DB::table('mod_whmpve_ips')->where('pool_id',$pool['id'])->where('state','free')->count();
            }
            unset($pool);
        }
        $out['instance_permissions']=$permissions;
        $out['instances'] = $permissions['view'] ? self::instances($server,$query) : ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
        $out['journal'] = $permissions['view'] ? self::journal($server,$query) : [];
        return $out;
    }

    private static function instances(int $server,array $input): array
    {
        $query = DB::table('mod_whmpve_instances as i')->leftJoin('tblhosting as h','h.id','=','i.service_id')
            ->leftJoin('tblclients as c','c.id','=','h.userid')->leftJoin('tblproducts as p','p.id','=','h.packageid')
            ->leftJoin('tblservers as s','s.id','=','i.server_id')->leftJoin('mod_whmpve_images as m','m.id','=','i.image_id');
        if ($server) { $query->where('i.server_id',$server); }
        if (!empty($input['service_id'])) { $query->where('i.service_id',(int)$input['service_id']); }
        $search = trim((string)($input['search'] ?? ''));
        if ($search!=='') {
            $query->where(function($q) use ($search) {
                foreach (['i.hostname','i.vmid','i.service_id','c.firstname','c.lastname','c.email','p.name','h.dedicatedip'] as $column) {
                    $q->orWhere($column,'like','%'.$search.'%');
                }
            });
        }
        $total = $query->count();
        $page = max(1,(int)($input['page'] ?? 1));
        $rows = $query->orderByDesc('i.id')->offset(($page-1)*25)->limit(25)->select(
            'i.*','h.userid as client_id','h.domainstatus as service_status','h.suspendreason','h.nextduedate','h.dedicatedip',
            'c.firstname','c.lastname','c.companyname','c.email','p.name as product_name','s.name as server_name','m.name as image_name'
        )->get()->map(function($row) {
            $r=(array)$row;
            $r['spec']=Util::unpack($r['applied'] ?: $r['desired']);
            $r['client_name']=trim(($r['firstname'] ?? '').' '.($r['lastname'] ?? ''));
            $r['client_url']='clientssummary.php?userid='.(int)$r['client_id'];
            $r['service_url']='clientsservices.php?userid='.(int)$r['client_id'].'&id='.$r['service_id'];
            $r['addresses']=Ipam::assigned($r);
            $r['config_revision']=InstanceConfig::revision($r,$r['addresses']);
            $r['ips']=$r['addresses'];
            $r['can_edit_config']=InstanceConfig::editable($r['lifecycle'],$r['service_status'] ?? '',$r['suspendreason']);
            if (!$r['ips'] && $r['dedicatedip']) { $r['ips']=[$r['dedicatedip']]; }
            unset($r['uuid'],$r['desired'],$r['applied'],$r['firstname'],$r['lastname'],$r['auto_backup_since'],$r['suspendreason']);
            return $r;
        })->all();
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25))];
    }

    private static function journal(int $server,array $input): array
    {
        $tasks = DB::table('mod_whmpve_tasks as t')->leftJoin('tblservers as s','s.id','=','t.server_id')
            ->leftJoin('mod_whmpve_instances as i','i.service_id','=','t.service_id');
        $logs = DB::table('mod_whmpve_logs as l')->leftJoin('tblservers as s','s.id','=','l.server_id');
        if ($server) { $tasks->where('t.server_id',$server); $logs->where('l.server_id',$server); }
        if (!empty($input['service_id'])) {
            $tasks->where('t.service_id',(int)$input['service_id']); $logs->where('l.service_id',(int)$input['service_id']);
        }
        $rows = [];
        foreach ($tasks->orderByDesc('t.id')->limit(100)->get(['t.*','s.name as server_name','i.hostname']) as $row) {
            $task=(array)$row;
            $rows[]=Jobs::public($task)+['type'=>'task','server_name'=>$row->server_name,'hostname'=>$row->hostname,'message'=>$row->error ?: $row->stage];
        }
        foreach ($logs->orderByDesc('l.id')->limit(100)->get(['l.*','s.name as server_name']) as $row) {
            $rows[]=(array)$row+['type'=>'log','status'=>$row->level,'action'=>'log','updated_at'=>$row->created_at];
        }
        usort($rows,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
        return $rows;
    }
}
