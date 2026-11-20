<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Catalog
{
    public const RESOURCE_OPTIONS = ['cores','memory_mib','swap_mib','disk_gib','bandwidth_mbps','ipv4_count','ipv6_count'];

    public static function spec(array $input, int $server): array
    {
        $default = (int)($input['default_image_id'] ?? 0);
        $image = $default ? Util::scoped('images',$default,$server) : null;
        $kind = $input['kind'] ?? '';
        if (!in_array($kind,['lxc','qemu'],true)) { throw new Fault('请选择虚拟化类型'); }
        if ($image && $image['kind']!==$kind) { throw new Fault('默认镜像类型与 Plan 类型不一致'); }
        $spec = ['kind'=>$kind]+Disks::spec($input,$server);
        foreach (['cores'=>[1,128,1],'memory_mib'=>[32,1048576,64],'swap_mib'=>[0,1048576,0],
            'bandwidth_mbps'=>[0,100000,100],
            'ipv4_count'=>[0,16,0],'ipv6_count'=>[0,16,0],'reinstall_limit'=>[0,10000,0],
            'traffic_speed_kbps'=>[1,100000000,256],'backup_limit'=>[0,10000,0]] as $key=>$range) {
            $spec[$key] = Util::integer($input[$key] ?? $range[2],$range[0],$range[1],$key);
        }
        $spec['disk_gib'] = Disks::size($input['disk_gib'] ?? 1);
        $spec['auto_backup'] = !empty($input['auto_backup']);
        $spec['cpu_limit'] = (float)($input['cpu_limit'] ?? $spec['cores']);
        if ($spec['cpu_limit'] < .1 || $spec['cpu_limit'] > $spec['cores']) {
            throw new Fault('CPU 上限不能超过核数');
        }
        $spec['default_image_id'] = $default;
        if ($spec['kind']==='qemu' && $spec['swap_mib']) { throw new Fault('KVM 的 Swap 请在镜像系统内配置'); }
        $spec['image_group_ids'] = self::references($input['image_group_ids'] ?? [],'image_groups',$server);
        $spec['ip_pool_ids'] = self::references($input['ip_pool_ids'] ?? [],'ip_pools',$server);
        foreach ([4,6] as $family) {
            if ($spec['ipv'.$family.'_count'] && !DB::table('mod_whmpve_ip_pools')->where('server_id',$server)
                ->whereIn('id',$spec['ip_pool_ids'])->where('family',$family)->exists()) { throw new Fault('请选择 IPv'.$family.' 地址池'); }
            if ($spec['ipv'.$family.'_count'] && DB::table('mod_whmpve_ip_pools')->whereIn('id',$spec['ip_pool_ids'])
                ->where('family',$family)->where('bridge','')->exists()) { throw new Fault('请先填写所选 IP 池的网桥'); }
        }
        if ($image && !self::imageAllowed($image,$spec)) { throw new Fault('默认镜像不属于所选镜像组，或配置低于镜像要求'); }
        $spec['traffic_gb'] = Traffic::quota($input['traffic_gb'] ?? 0);
        $spec['traffic_action'] = $input['traffic_action'] ?? 'throttle';
        if (!in_array($spec['traffic_action'],['throttle','suspend'],true)) { throw new Fault('请选择流量超额处理方式'); }
        $spec['allowed_options'] = [];
        foreach (($input['allowed_options'] ?? []) as $key=>$values) {
            if (!in_array($key,self::RESOURCE_OPTIONS,true) || !is_array($values)) { throw new Fault('可选规格无效'); }
            $spec['allowed_options'][$key] = array_values(array_unique(array_map($key==='disk_gib' ? [Disks::class,'size'] : 'intval',$values)));
        }
        return $spec;
    }

    private static function references(mixed $ids,string $table,int $server): array
    {
        if (!is_array($ids)) { throw new Fault('请选择有效的配置'); }
        $ids = array_values(array_unique(array_map('intval',$ids)));
        foreach ($ids as $id) { Util::scoped($table,$id,$server); }
        return $ids;
    }

    public static function imageAllowed(array $image,array $spec): bool
    {
        $groups = $spec['image_group_ids'] ?? [];
        return !empty($image['active']) && $image['kind']===$spec['kind']
            && (int)$image['min_memory']<=$spec['memory_mib'] && (float)$image['min_disk']<=$spec['disk_gib']
            && (!$groups || empty($image['group_id']) || in_array((int)$image['group_id'],$groups,true));
    }

    public static function images(int $server,array $spec): array
    {
        return DB::table('mod_whmpve_images')->where('server_id',$server)->orderBy('id')->get()
            ->map(fn($row)=>(array)$row)->filter(fn($row)=>self::imageAllowed($row,$spec))->values()->all();
    }

    public static function reinstallCount(int $service): int
    {
        $month = Util::month();
        return DB::table('mod_whmpve_tasks')->where('service_id',$service)->where('action','reinstall')
            ->where('actor','like','client:%')->whereIn('status',['queued','running','success'])
            ->where('created_at','>=',gmdate('Y-m-d H:i:s',$month['start']))->count();
    }

    public static function saveGroup(array $input): int
    {
        $server = (int)($input['server_id'] ?? 0); ServerConfig::get($server);
        $id = (int)($input['id'] ?? 0);
        if ($id) { Util::scoped('image_groups',$id,$server); }
        $name = Util::label((string)($input['name'] ?? ''));
        if (DB::table('mod_whmpve_image_groups')->where('server_id',$server)->where('name',$name)->where('id','!=',$id)->exists()) { throw new Fault('镜像组名称已存在'); }
        return self::save('image_groups',$id,['server_id'=>$server,'name'=>$name]);
    }

    public static function savePlan(array $input): int
    {
        $server = (int)($input['server_id'] ?? 0);
        ServerConfig::get($server);
        $id = (int)($input['id'] ?? 0);
        if ($id) { Util::scoped('plans',$id,$server); }
        return DB::connection()->transaction(function () use ($input,$server,$id) {
            DB::table('mod_whmpve_servers')->where('id',$server)->lockForUpdate()->first();
            $spec = self::spec($input,$server);
            $previous=[];
            if ($id) {
                $old = DB::table('mod_whmpve_plans')->where('id',$id)->where('server_id',$server)->lockForUpdate()->first()
                    ?? throw new Fault('Plan 不存在',404);
                $previous=Util::unpack($old->spec);
                if ($previous['kind']!==$spec['kind'] && in_array($id,self::usedPlans($server),true)) {
                    throw new Fault('该 Plan 已有订单，更换类型请新建 Plan');
                }
            }
            $name = Util::label((string)($input['name'] ?? ''));
            $duplicates = DB::table('mod_whmpve_plans')->where('server_id',$server)->where('name',$name);
            if ($id) { $duplicates->where('id','!=',$id); }
            if ($duplicates->exists()) { throw new Fault('该服务器已存在同名 Plan'); }
            if (($input['preserve_unlisted_options'] ?? false)===true) {
                $spec['allowed_options']+=array_intersect_key($previous['allowed_options'] ?? [],
                    array_flip(['swap_mib','ipv4_count','ipv6_count']));
            }
            $display=$previous['connection_display'] ?? [];
            if (array_key_exists('connection_display',$input)) {
                $display=self::connectionDisplay($input['connection_display'],$display);
            }
            if ($display) { $spec['connection_display']=$display; }
            $data=['server_id'=>$server,'name'=>$name,'spec'=>Util::json($spec)];
            $saved=self::save('plans',$id,$data);
            foreach (DB::table('tblproducts')->where('servertype','whmpve')->where('configoption1',$server)->where('configoption2',$saved)->pluck('id') as $product) { Disks::syncProduct((int)$product); }
            return $saved;
        });
    }

    private static function connectionDisplay(mixed $input,array $previous): array
    {
        if (!is_array($input)) { throw new Fault('连接信息格式无效'); }
        if (empty($input['private_address'])) { return ['private_address'=>false]+$previous; }
        $ip=$input['public_ip'] ?? '';
        $note=$input['port_note'] ?? '';
        if (!is_string($ip) || (trim($ip)!=='' && !filter_var(trim($ip),FILTER_VALIDATE_IP))) {
            throw new Fault('请输入有效的公网入口 IP');
        }
        if (!is_string($note)) { throw new Fault('分配的端口请输入文字'); }
        return ['private_address'=>true,'public_ip'=>trim($ip),'port_note'=>trim($note)];
    }

    public static function usedPlans(int $server): array
    {
        $instances = DB::table('mod_whmpve_instances')->where('server_id',$server)->distinct()->pluck('plan_id')->all();
        $orders = DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
            ->where('p.servertype','whmpve')->where('p.configoption1',$server)->distinct()->pluck('p.configoption2')->all();
        return array_values(array_unique(array_map('intval',array_merge($instances,$orders))));
    }

    public static function saveImage(array $input): int
    {
        $server = (int)($input['server_id'] ?? 0);
        ServerConfig::get($server);
        $id = (int)($input['id'] ?? 0);
        $old = $id ? Util::scoped('images',$id,$server) : null;
        $kind = $input['kind'] ?? 'lxc';
        if (!in_array($kind,['lxc','qemu'],true)) { throw new Fault('镜像类型无效'); }
        if ($old && $old['kind']!==$kind) { throw new Fault('更换类型请新建镜像'); }
        $group = empty($input['group_id']) ? null : (int)$input['group_id'];
        if ($group) { Util::scoped('image_groups',$group,$server); }
        $source = trim((string)($input['source'] ?? ''));
        $valid = $kind==='lxc'
            ? preg_match('/^[a-zA-Z0-9_.-]+:vztmpl\/[a-zA-Z0-9_.-]+\.tar\.(xz|gz|zst)$/',$source)
            : ctype_digit($source);
        if (!$valid) { throw new Fault($kind==='lxc' ? '请填写模板卷名，例如 local:vztmpl/alpine.tar.xz' : '请填写 KVM 模板 VMID'); }
        $description=$input['description'] ?? ($old['description'] ?? '');
        if (!is_string($description) || mb_strlen($description)>500) { throw new Fault('镜像描述最多 500 字'); }
        return self::save('images',$id,[
            'server_id'=>$server,'name'=>Util::label((string)($input['name'] ?? '')),'kind'=>$kind,'source'=>$source,'group_id'=>$group,
            'min_memory'=>Util::integer($input['min_memory'] ?? 64,16,1048576,'最低内存'),
            'min_disk'=>Disks::size($input['min_disk'] ?? 1),
            'description'=>trim($description),
        ]);
    }

    private static function save(string $table,int $id,array $data): int
    {
        $data['updated_at'] = Util::now();
        if ($id) { DB::table('mod_whmpve_'.$table)->where('id',$id)->update($data); return $id; }
        $data['created_at'] = Util::now();
        return DB::table('mod_whmpve_'.$table)->insertGetId($data);
    }

    public static function delete(string $table,int $id,int $server): void
    {
        if ($table==='ip_pools') { Ipam::removePool($id,$server); return; }
        if (!in_array($table,['plans','images','ips','image_groups'],true)) { throw new Fault('记录类型无效'); }
        Util::scoped($table,$id,$server);
        if ($table==='ips') {
            if (!DB::table('mod_whmpve_ips')->where('id',$id)->where('server_id',$server)->where('state','free')->delete()) {
                throw new Fault('该 IP 已分配或已删除',409);
            }
            return;
        }
        if ($table==='plans') {
            DB::connection()->transaction(function() use($id,$server) {
                Ipam::lockServer($server);
                if (DB::table('mod_whmpve_instances')->where('plan_id',$id)->exists()
                    || DB::table('tblproducts')->where('servertype','whmpve')->where('configoption2',$id)->exists()) {
                    throw new Fault('Plan 已关联产品或实例，请先停用');
                }
                DB::table('mod_whmpve_addons')->where('server_id',$server)->where('plan_id',$id)->delete();
                DB::table('mod_whmpve_plans')->where('server_id',$server)->where('id',$id)->delete();
            });
            return;
        } elseif ($table==='images') {
            foreach (DB::table('mod_whmpve_plans')->where('server_id',$server)->get(['spec']) as $plan) {
                if ($id===(int)Util::unpack($plan->spec)['default_image_id']) { throw new Fault('请先更换 Plan 的默认镜像'); }
            }
            if (DB::table('mod_whmpve_instances')->where('image_id',$id)->exists()) { throw new Fault('镜像已有实例记录，请先停用'); }
            if (DB::table('mod_whmpve_backups')->where('server_id',$server)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(manifest, '$.image_id')) = ?",[(string)$id])->exists()) {
                throw new Fault('镜像仍被备份引用，请先停用');
            }
        } elseif ($table==='image_groups') {
            foreach (DB::table('mod_whmpve_plans')->where('server_id',$server)->get(['spec']) as $plan) {
                if (in_array($id,Util::unpack($plan->spec)['image_group_ids'] ?? [],true)) { throw new Fault('请先从 Plan 移除此镜像组'); }
            }
            DB::table('mod_whmpve_images')->where('server_id',$server)->where('group_id',$id)->update(['group_id'=>null]);
        }
        DB::table('mod_whmpve_'.$table)->where('id',$id)->delete();
    }

    public static function resolve(array $params): array
    {
        $server = (int)($params['configoption1'] ?? 0);
        $config = ServerConfig::get($server);
        if (!$config['active']) { throw new Fault('所选服务器已停用'); }
        $plan = Util::scoped('plans',(int)($params['configoption2'] ?? 0),$server);
        if (!$plan['active']) { throw new Fault('所选 Plan 已停用'); }
        $spec = Util::unpack($plan['spec']);
        $baseCores=(int)$spec['cores'];
        $baseLimit=(float)($spec['cpu_limit'] ?? $baseCores);
        foreach (($params['configoptions'] ?? []) as $key=>$value) {
            $key = explode('|',(string)$key)[0];
            $value = explode('|',(string)$value)[0];
            if (!in_array($key,self::RESOURCE_OPTIONS,true)) { continue; }
            if (!isset($spec['allowed_options'][$key])) { throw new Fault('可选规格不属于所选 Plan'); }
            if ($key==='disk_gib') {
                $value=Disks::size($value);
                $choices=array_map([Disks::class,'size'],$spec['allowed_options'][$key]);
            } else {
                if (!ctype_digit((string)$value)) { throw new Fault('可选规格不属于所选 Plan'); }
                $value=(int)$value; $choices=$spec['allowed_options'][$key];
            }
            if (!in_array($value,$choices,true)) { throw new Fault('可选规格不属于所选 Plan'); }
            $spec[$key] = $value;
        }
        if ((int)$spec['cores']!==$baseCores) {
            $spec['cpu_limit']=$baseLimit===(float)$baseCores ? $spec['cores'] : min($baseLimit,$spec['cores']);
        }
        $spec = Disks::custom(self::spec($spec,$server),$params['customfields'] ?? []);
        if (!$spec['default_image_id']) { throw new Fault('请先为 Plan 设置默认镜像'); }
        return ['server'=>$config,'plan'=>$plan,'spec'=>$spec];
    }

    public static function assertServerBinding(array $instance,int $nativeServer): void
    {
        if ((int)$instance['server_id']!==$nativeServer) { throw new Fault('WHMCS 的服务器与实例绑定不一致',409); }
    }

    public static function params(int $service): array
    {
        $s = DB::table('tblhosting')->where('id',$service)->first();
        if (!$s) { throw new Fault('服务不存在',404); }
        $p = DB::table('tblproducts')->where('id',$s->packageid)->first();
        if (!$p || $p->servertype!=='whmpve') { throw new Fault('服务不属于 WHMPVE',404); }
        $params = ['serviceid'=>$service,'userid'=>(int)$s->userid,'pid'=>(int)$s->packageid,'serverid'=>(int)$s->server,
            'domain'=>$s->domain,'username'=>$s->username,'password'=>$s->password ? decrypt($s->password) : '',
            'configoption1'=>$p->configoption1,'configoption2'=>$p->configoption2,'configoptions'=>[],'customfields'=>[]];
        $fields=DB::table('tblcustomfields as f')->join('tblcustomfieldsvalues as v','v.fieldid','=','f.id')
            ->where('f.type','product')->where('f.relid',$p->id)->where('v.relid',$service)->get(['f.fieldname','v.value']);
        foreach ($fields as $field) { $params['customfields'][explode('|',$field->fieldname)[0]]=$field->value; }
        $options = DB::table('tblhostingconfigoptions as h')->join('tblproductconfigoptions as o','o.id','=','h.configid')
            ->join('tblproductconfigoptionssub as v','v.id','=','h.optionid')->where('h.relid',$service)
            ->select('o.optionname','o.optiontype','v.optionname as value','h.qty')->get();
        foreach ($options as $o) {
            $params['configoptions'][explode('|',$o->optionname)[0]] = (int)$o->optiontype===4 ? $o->qty : explode('|',$o->value)[0];
        }
        return $params;
    }

    public static function freeze(array $params): array
    {
        return DB::connection()->transaction(function () use ($params) {
            $old = DB::table('mod_whmpve_instances')->where('service_id',$params['serviceid'])->lockForUpdate()->first();
            if ($old) { return (array)$old; }
            DB::table('mod_whmpve_plans')->where('id',(int)($params['configoption2'] ?? 0))
                ->where('server_id',(int)($params['configoption1'] ?? 0))->lockForUpdate()->first();
            $resolved = self::resolve($params);
            $spec = $resolved['spec'];
            $hostname = strtolower((string)($params['domain'] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/',$hostname)) { $hostname = 'vps-'.$params['serviceid']; }
            $id = DB::table('mod_whmpve_instances')->insertGetId([
                'service_id'=>(int)$params['serviceid'],'server_id'=>$resolved['server']['id'],
                'plan_id'=>$resolved['plan']['id'],'image_id'=>$spec['default_image_id'],'uuid'=>bin2hex(random_bytes(16)),
                'kind'=>$spec['kind'],'hostname'=>$hostname,'lifecycle'=>'pending','desired'=>Util::json($spec),
                'created_at'=>Util::now(),'updated_at'=>Util::now(),
            ]);
            DB::table('tblhosting')->where('id',$params['serviceid'])->update(['server'=>$resolved['server']['id']]);
            return Util::row('instances',$id);
        });
    }

    public static function freezeOrder(int $order): void
    {
        $ids = DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
            ->where('h.orderid',$order)->where('p.servertype','whmpve')->pluck('h.id');
        foreach ($ids as $id) {
            try { self::freeze(self::params((int)$id)); }
            catch (\Throwable $e) { Util::log($e->getMessage(),0,(int)$id); }
        }
    }
}
