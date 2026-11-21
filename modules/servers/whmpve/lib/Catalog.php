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
        if (!in_array($kind,['lxc','qemu'],true)) { throw Fault::localized('message.select_a_virtualization_type'); }
        if ($image && $image['kind']!==$kind) { throw Fault::localized('message.the_default_image_does_not_match_the'); }
        $spec = ['kind'=>$kind]+Disks::spec($input,$server);
        if ($kind==='lxc') { $spec['nesting']=(bool)($input['nesting'] ?? true); }
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
            throw Fault::localized('message.the_cpu_limit_cannot_exceed_the_number');
        }
        $spec['default_image_id'] = $default;
        if ($spec['kind']==='qemu' && $spec['swap_mib']) { throw Fault::localized('message.configure_swap_inside_the_guest_os_for'); }
        $spec['image_group_ids'] = self::references($input['image_group_ids'] ?? [],'image_groups',$server);
        $spec['ip_pool_ids'] = self::references($input['ip_pool_ids'] ?? [],'ip_pools',$server);
        foreach ([4,6] as $family) {
            if ($spec['ipv'.$family.'_count'] && !DB::table('mod_whmpve_ip_pools')->where('server_id',$server)
                ->whereIn('id',$spec['ip_pool_ids'])->where('family',$family)->exists()) { throw Fault::localized('message.select_ip_pool',422,['family'=>$family]); }
            if ($spec['ipv'.$family.'_count'] && DB::table('mod_whmpve_ip_pools')->whereIn('id',$spec['ip_pool_ids'])
                ->where('family',$family)->where('bridge','')->exists()) { throw Fault::localized('message.set_a_bridge_for_the_selected_ip'); }
        }
        if ($image && !self::imageAllowed($image,$spec)) { throw Fault::localized('message.the_default_image_is_outside_the_selected'); }
        $spec['traffic_gb'] = Traffic::quota($input['traffic_gb'] ?? 0);
        $spec['traffic_action'] = $input['traffic_action'] ?? 'throttle';
        if (!in_array($spec['traffic_action'],['throttle','suspend'],true)) { throw Fault::localized('message.select_what_to_do_when_traffic_exceeds'); }
        $spec['allowed_options'] = [];
        foreach (($input['allowed_options'] ?? []) as $key=>$values) {
            if (!in_array($key,self::RESOURCE_OPTIONS,true) || !is_array($values)) { throw Fault::localized('message.invalid_configurable_resources'); }
            $spec['allowed_options'][$key] = array_values(array_unique(array_map($key==='disk_gib' ? [Disks::class,'size'] : 'intval',$values)));
        }
        return $spec;
    }

    private static function references(mixed $ids,string $table,int $server): array
    {
        if (!is_array($ids)) { throw Fault::localized('message.select_a_valid_configuration'); }
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
        if (DB::table('mod_whmpve_image_groups')->where('server_id',$server)->where('name',$name)->where('id','!=',$id)->exists()) { throw Fault::localized('message.an_image_group_with_this_name_already'); }
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
            $previous=[];
            if ($id) {
                $old = DB::table('mod_whmpve_plans')->where('id',$id)->where('server_id',$server)->lockForUpdate()->first()
                    ?? throw Fault::localized('message.plan_not_found',404);
                $previous=Util::unpack($old->spec);
            }
            $spec = self::spec($input+array_intersect_key($previous,['nesting'=>true]),$server);
            if ($id && $previous['kind']!==$spec['kind'] && in_array($id,self::usedPlans($server),true)) {
                throw Fault::localized('message.orders_already_exist_for_this_plan_create');
            }
            $name = Util::label((string)($input['name'] ?? ''));
            $duplicates = DB::table('mod_whmpve_plans')->where('server_id',$server)->where('name',$name);
            if ($id) { $duplicates->where('id','!=',$id); }
            if ($duplicates->exists()) { throw Fault::localized('message.a_plan_with_that_name_already_exists'); }
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
        if (!is_array($input)) { throw Fault::localized('message.invalid_connection_details_format'); }
        if (empty($input['private_address'])) { return ['private_address'=>false]+$previous; }
        $ip=$input['public_ip'] ?? '';
        $note=$input['port_note'] ?? '';
        if (!is_string($ip) || (trim($ip)!=='' && !filter_var(trim($ip),FILTER_VALIDATE_IP))) {
            throw Fault::localized('message.enter_a_valid_public_ingress_ip');
        }
        if (!is_string($note)) { throw Fault::localized('message.enter_the_assigned_ports_as_text'); }
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
        if (!in_array($kind,['lxc','qemu'],true)) { throw Fault::localized('message.invalid_image_type'); }
        if ($old && $old['kind']!==$kind) { throw Fault::localized('message.create_a_new_image_to_use_a'); }
        $group = empty($input['group_id']) ? null : (int)$input['group_id'];
        if ($group) { Util::scoped('image_groups',$group,$server); }
        $source = trim((string)($input['source'] ?? ''));
        $valid = $kind==='lxc'
            ? preg_match('/^[a-zA-Z0-9_.-]+:vztmpl\/[a-zA-Z0-9_.-]+\.tar\.(xz|gz|zst)$/',$source)
            : ctype_digit($source);
        if (!$valid) { throw new Fault($kind==='lxc' ? Lang::text('message.enter_the_template_volume_for_example_local') : Lang::text('message.enter_the_kvm_template_vmid')); }
        $description=$input['description'] ?? ($old['description'] ?? '');
        if (!is_string($description) || mb_strlen($description)>500) { throw Fault::localized('message.an_image_description_may_contain_at_most'); }
        return self::save('images',$id,[
            'server_id'=>$server,'name'=>Util::label((string)($input['name'] ?? '')),'kind'=>$kind,'source'=>$source,'group_id'=>$group,
            'min_memory'=>Util::integer($input['min_memory'] ?? 64,16,1048576,Lang::text('message.minimum_memory')),
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
        if (!in_array($table,['plans','images','ips','image_groups'],true)) { throw Fault::localized('message.invalid_record_type'); }
        Util::scoped($table,$id,$server);
        if ($table==='ips') {
            if (!DB::table('mod_whmpve_ips')->where('id',$id)->where('server_id',$server)->where('state','free')->delete()) {
                throw Fault::localized('message.this_ip_address_has_already_been_assigned',409);
            }
            return;
        }
        if ($table==='plans') {
            DB::connection()->transaction(function() use($id,$server) {
                Ipam::lockServer($server);
                if (DB::table('mod_whmpve_instances')->where('plan_id',$id)->exists()
                    || DB::table('tblproducts')->where('servertype','whmpve')->where('configoption2',$id)->exists()) {
                    throw Fault::localized('message.this_plan_is_linked_to_products_or');
                }
                DB::table('mod_whmpve_addons')->where('server_id',$server)->where('plan_id',$id)->delete();
                DB::table('mod_whmpve_plans')->where('server_id',$server)->where('id',$id)->delete();
            });
            return;
        } elseif ($table==='images') {
            foreach (DB::table('mod_whmpve_plans')->where('server_id',$server)->get(['spec']) as $plan) {
                if ($id===(int)Util::unpack($plan->spec)['default_image_id']) { throw Fault::localized('message.change_the_plans_default_image_first'); }
            }
            if (DB::table('mod_whmpve_instances')->where('image_id',$id)->exists()) { throw Fault::localized('message.this_image_is_linked_to_existing_instances'); }
            if (DB::table('mod_whmpve_backups')->where('server_id',$server)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(manifest, '$.image_id')) = ?",[(string)$id])->exists()) {
                throw Fault::localized('message.a_backup_still_references_this_image_disable');
            }
        } elseif ($table==='image_groups') {
            foreach (DB::table('mod_whmpve_plans')->where('server_id',$server)->get(['spec']) as $plan) {
                if (in_array($id,Util::unpack($plan->spec)['image_group_ids'] ?? [],true)) { throw Fault::localized('message.remove_this_image_group_from_the_plans'); }
            }
            DB::table('mod_whmpve_images')->where('server_id',$server)->where('group_id',$id)->update(['group_id'=>null]);
        }
        DB::table('mod_whmpve_'.$table)->where('id',$id)->delete();
    }

    public static function resolve(array $params): array
    {
        $server = (int)($params['configoption1'] ?? 0);
        $config = ServerConfig::get($server);
        if (!$config['active']) { throw Fault::localized('message.the_selected_server_is_disabled'); }
        $plan = Util::scoped('plans',(int)($params['configoption2'] ?? 0),$server);
        if (!$plan['active']) { throw Fault::localized('message.the_selected_plan_is_disabled'); }
        $spec = Util::unpack($plan['spec']);
        $baseCores=(int)$spec['cores'];
        $baseLimit=(float)($spec['cpu_limit'] ?? $baseCores);
        foreach (($params['configoptions'] ?? []) as $key=>$value) {
            $key = explode('|',(string)$key)[0];
            $value = explode('|',(string)$value)[0];
            if (!in_array($key,self::RESOURCE_OPTIONS,true)) { continue; }
            if (!isset($spec['allowed_options'][$key])) { throw Fault::localized('message.the_configured_resources_are_outside_the_selected'); }
            if ($key==='disk_gib') {
                $value=Disks::size($value);
                $choices=array_map([Disks::class,'size'],$spec['allowed_options'][$key]);
            } else {
                if (!ctype_digit((string)$value)) { throw Fault::localized('message.the_configured_resources_are_outside_the_selected'); }
                $value=(int)$value; $choices=$spec['allowed_options'][$key];
            }
            if (!in_array($value,$choices,true)) { throw Fault::localized('message.the_configured_resources_are_outside_the_selected'); }
            $spec[$key] = $value;
        }
        if ((int)$spec['cores']!==$baseCores) {
            $spec['cpu_limit']=$baseLimit===(float)$baseCores ? $spec['cores'] : min($baseLimit,$spec['cores']);
        }
        $spec = Disks::custom(self::spec($spec,$server),$params['customfields'] ?? []);
        if (!$spec['default_image_id']) { throw Fault::localized('message.set_a_default_image_for_the_plan'); }
        return ['server'=>$config,'plan'=>$plan,'spec'=>$spec];
    }

    public static function assertServerBinding(array $instance,int $nativeServer): void
    {
        if ((int)$instance['server_id']!==$nativeServer) { throw Fault::localized('message.the_whmcs_server_does_not_match_the',409); }
    }

    public static function params(int $service): array
    {
        $s = DB::table('tblhosting')->where('id',$service)->first();
        if (!$s) { throw Fault::localized('message.service_not_found',404); }
        $p = DB::table('tblproducts')->where('id',$s->packageid)->first();
        if (!$p || $p->servertype!=='whmpve') { throw Fault::localized('message.this_service_does_not_belong_to_whmpve',404); }
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
