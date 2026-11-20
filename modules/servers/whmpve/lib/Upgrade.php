<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

/** One-time conversion of the 0.1 configuration model. */
final class Upgrade
{
    public static function disks(): void
    {
        $column=DB::selectOne("SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mod_whmpve_images' AND COLUMN_NAME='min_disk'");
        if ($column->type!=='decimal') { DB::statement('ALTER TABLE mod_whmpve_images MODIFY min_disk DECIMAL(16,10) NOT NULL'); }
        if (!DB::schema()->hasColumn('mod_whmpve_instances','disks')) {
            DB::schema()->table('mod_whmpve_instances',fn($t)=>$t->longText('disks')->nullable());
        }
        if (Util::setting('disk_features_migrated')) { return; }
        DB::connection()->transaction(function() {
            foreach (DB::table('mod_whmpve_servers')->get() as $server) {
                $storage=Util::unpack($server->settings)['storage'];
                foreach (['plans'=>['spec'],'instances'=>['desired','applied']] as $table=>$fields) {
                    foreach (DB::table('mod_whmpve_'.$table)->where('server_id',$server->id)->get() as $row) {
                        $values=[];
                        foreach ($fields as $field) {
                            if (!$row->$field) { continue; }
                            $spec=Util::unpack($row->$field)+['system_storage'=>$storage,'data_disks'=>[]];
                            $values[$field]=Util::json($spec);
                        }
                        if ($values) { DB::table('mod_whmpve_'.$table)->where('id',$row->id)->update($values); }
                    }
                }
            }
            DB::table('tbladdonmodules')->updateOrInsert(['module'=>'whmpve_manager','setting'=>'disk_features_migrated'],['value'=>'1']);
        });
    }

    public static function features(): void
    {
        $schema = DB::schema();
        if (!$schema->hasColumn('mod_whmpve_images','group_id')) {
            $schema->table('mod_whmpve_images',fn($t) => $t->unsignedInteger('group_id')->nullable()->index());
        }
        if (!$schema->hasColumn('mod_whmpve_ips','pool_id')) {
            $schema->table('mod_whmpve_ips',function($t) {
                $t->unsignedInteger('pool_id')->default(0)->index(); $t->unsignedTinyInteger('family')->default(4);
            });
        }
        if (Util::setting('network_features_migrated')) { return; }
        DB::connection()->transaction(function() {
            foreach (DB::table('mod_whmpve_servers')->get() as $server) {
                $settings = Util::unpack($server->settings);
                $pools = [];
                foreach (DB::table('mod_whmpve_ips')->where('server_id',$server->id)->where('pool_id',0)->get() as $ip) {
                    $key = $ip->prefix.'|'.$ip->gateway;
                    if (!isset($pools[$key])) {
                        $pools[$key] = DB::table('mod_whmpve_ip_pools')->insertGetId(['server_id'=>$server->id,'name'=>'IPv4 '.(count($pools)+1),
                            'family'=>4,'prefix'=>$ip->prefix,'gateway'=>$ip->gateway,'bridge'=>$settings['bridge'] ?? '',
                            'vlan'=>$settings['vlan'] ?? null,'dns'=>'','mtu'=>1500,'created_at'=>Util::now(),'updated_at'=>Util::now()]);
                    }
                    DB::table('mod_whmpve_ips')->where('id',$ip->id)->update(['pool_id'=>$pools[$key]]);
                }
                foreach (DB::table('mod_whmpve_plans')->where('server_id',$server->id)->get() as $plan) {
                    DB::table('mod_whmpve_plans')->where('id',$plan->id)->update(['spec'=>self::featureSpec($plan->spec,array_values($pools))]);
                }
                foreach (DB::table('mod_whmpve_instances')->where('server_id',$server->id)->get() as $instance) {
                    $ownPools = DB::table('mod_whmpve_ips')->where('service_id',$instance->service_id)->distinct()->pluck('pool_id')->all();
                    DB::table('mod_whmpve_instances')->where('id',$instance->id)->update([
                        'desired'=>self::featureSpec($instance->desired,$ownPools ?: array_values($pools)),
                        'applied'=>$instance->applied ? self::featureSpec($instance->applied,$ownPools ?: array_values($pools)) : null,
                    ]);
                }
            }
            $seen = [];
            foreach (DB::table('mod_whmpve_ips')->orderByRaw("state = 'free'")->orderBy('id')->get() as $ip) {
                $address = Ipam::address($ip->address,(int)$ip->family);
                if (isset($seen[$address])) {
                    if ($ip->state!=='free') { throw new Fault('升级前请处理重复分配的 IP：'.$address); }
                    DB::table('mod_whmpve_ips')->where('id',$ip->id)->delete();
                } else {
                    $seen[$address] = true;
                    DB::table('mod_whmpve_ips')->where('id',$ip->id)->update(['address'=>$address]);
                }
            }
        });
        self::dropIndex('ips','mod_whmpve_ips_server_id_address_unique');
        $indexes = DB::select('SHOW INDEX FROM mod_whmpve_ips');
        if (!array_filter($indexes,fn($i)=>$i->Key_name==='mod_whmpve_ips_address_unique')) {
            $schema->table('mod_whmpve_ips',fn($t) => $t->unique('address'));
        }
        DB::table('tbladdonmodules')->updateOrInsert(['module'=>'whmpve_manager','setting'=>'network_features_migrated'],['value'=>'1']);
    }

    private static function featureSpec(string $value,array $pools): string
    {
        $spec = Util::unpack($value);
        $spec += ['swap_mib'=>0,'ipv6_count'=>0,'ip_pool_ids'=>$pools,'image_group_ids'=>[],
            'traffic_gb'=>0,'traffic_action'=>'throttle','traffic_speed_kbps'=>256,'reinstall_limit'=>0];
        unset($spec['image_ids']);
        return Util::json($spec);
    }

    public static function run(): void
    {
        $schema = DB::schema();
        if (!$schema->hasTable('mod_whmpve_pools')) { return; }
        foreach (['plans','images','ips','tasks'] as $table) {
            if (!$schema->hasColumn('mod_whmpve_'.$table,'server_id')) {
                $schema->table('mod_whmpve_'.$table, fn($t) => $t->unsignedInteger('server_id')->default(0)->index());
            }
        }
        if (!$schema->hasColumn('mod_whmpve_instances','power_state')) {
            $schema->table('mod_whmpve_instances', function($t) {
                $t->string('power_state',16)->default('unknown');
                $t->dateTime('power_updated_at')->nullable();
            });
        }

        if (!Util::setting('server_model_migrated')) {
            DB::connection()->transaction(function () {
                $contexts = [];
                $used = [];
                foreach (DB::table('mod_whmpve_pools')->orderBy('id')->get() as $pool) {
                    $settings = Util::unpack($pool->settings);
                    foreach ($settings['nodes'] as $node) {
                        $native = (array)DB::table('tblservers')->where('id',$pool->server_id)->first();
                        if (!$native) { throw new Fault('旧服务器记录不存在：'.$pool->server_id); }
                        $id = (int)$native['id'];
                        if (isset($used[$id])) {
                            unset($native['id']);
                            $native['name'] = mb_substr($native['name'].' / '.$node,0,64);
                            $id = DB::table('tblservers')->insertGetId($native);
                        }
                        $used[$id] = true;
                        $settings['node'] = $node;
                        unset($settings['nodes']);
                        DB::table('mod_whmpve_servers')->insert([
                            'id'=>$id,'settings'=>Util::json($settings),'active'=>$pool->active,
                            'created_at'=>$pool->created_at,'updated_at'=>Util::now(),
                        ]);
                        $contexts[$pool->id][$node] = $id;
                    }
                }
                $servers = array_keys($used);
                if (!$servers && DB::table('mod_whmpve_images')->exists()) {
                    throw new Fault('升级前请先配置服务器');
                }
                $imageMap = self::copyCatalog('images',$servers);
                $planMap = self::copyCatalog('plans',$servers,$imageMap);

                foreach (DB::table('mod_whmpve_instances')->get() as $instance) {
                    $choices = $contexts[$instance->pool_id];
                    $server = $choices[$instance->node] ?? reset($choices);
                    $values = [
                        'server_id'=>$server,'plan_id'=>$planMap[$server][$instance->plan_id],
                        'image_id'=>$imageMap[$server][$instance->image_id],
                        'desired'=>self::spec($instance->desired,$imageMap[$server]),
                        'applied'=>$instance->applied ? self::spec($instance->applied,$imageMap[$server]) : null,
                        'power_state'=>$instance->lifecycle==='terminated' ? 'absent' : 'unknown',
                    ];
                    DB::table('mod_whmpve_instances')->where('id',$instance->id)->update($values);
                    DB::table('tblhosting')->where('id',$instance->service_id)->update(['server'=>$server]);
                    DB::table('mod_whmpve_tasks')->where('service_id',$instance->service_id)->update(['server_id'=>$server]);
                }
                foreach (DB::table('tblproducts')->where('servertype','whmpve')->get() as $product) {
                    $choices = $contexts[(int)$product->configoption2] ?? [];
                    if (!$choices) { continue; }
                    $server = reset($choices);
                    DB::table('tblproducts')->where('id',$product->id)->update([
                        'configoption1'=>$server,'configoption2'=>$planMap[$server][(int)$product->configoption1],'servergroup'=>0,
                    ]);
                }
                foreach (DB::table('mod_whmpve_ips')->get() as $ip) {
                    $choices = $contexts[$ip->pool_id];
                    $server = $ip->service_id
                        ? DB::table('mod_whmpve_instances')->where('service_id',$ip->service_id)->value('server_id')
                        : reset($choices);
                    DB::table('mod_whmpve_ips')->where('id',$ip->id)->update(['server_id'=>$server]);
                }
                if (DB::schema()->hasTable('mod_whmpve_audit')) {
                    foreach (DB::table('mod_whmpve_audit')->whereIn('action',['http.error','snapshot.failed','payment.provisioning_failed'])->get() as $entry) {
                        $details = Util::unpack($entry->details);
                        $server = $entry->service_id ? (int)DB::table('mod_whmpve_instances')->where('service_id',$entry->service_id)->value('server_id') : 0;
                        Util::log($details['error'] ?? $entry->action,$server,(int)$entry->service_id);
                    }
                }
                DB::table('tbladdonmodules')->updateOrInsert(
                    ['module'=>'whmpve_manager','setting'=>'server_model_migrated'],['value'=>'1']
                );
            });
        }

        self::dropIndex('plans','mod_whmpve_plans_code_version_unique');
        self::dropIndex('ips','mod_whmpve_ips_pool_id_address_unique');
        foreach (['plans'=>['code','version'],'images'=>['source_node','arch'],'instances'=>['pool_id','client_id'],'ips'=>['pool_id']] as $table=>$columns) {
            foreach ($columns as $column) {
                if ($schema->hasColumn('mod_whmpve_'.$table,$column)) {
                    $schema->table('mod_whmpve_'.$table,fn($t) => $t->dropColumn($column));
                }
            }
        }
        $schema->table('mod_whmpve_ips',fn($t) => $t->unique(['server_id','address']));
        $schema->dropIfExists('mod_whmpve_pools');
        $schema->dropIfExists('mod_whmpve_audit');
    }

    private static function copyCatalog(string $table,array $servers,array $images=[]): array
    {
        $map = [];
        $rows = DB::table('mod_whmpve_'.$table)->where('server_id',0)->orderBy('id')->get();
        foreach ($servers as $position=>$server) {
            foreach ($rows as $row) {
                $data = (array)$row;
                $oldId = (int)$data['id'];
                $data['server_id'] = $server;
                if ($table==='plans') { $data['spec'] = self::spec($data['spec'],$images[$server]); }
                if ($position===0) {
                    DB::table('mod_whmpve_'.$table)->where('id',$oldId)->update($data);
                    $id = $oldId;
                } else {
                    unset($data['id']);
                    if ($table==='plans') { $data['code'] .= '-s'.$server; }
                    $id = DB::table('mod_whmpve_'.$table)->insertGetId($data);
                }
                $map[$server][$oldId] = $id;
            }
        }
        return $map;
    }

    private static function spec(string $json,array $images): string
    {
        $spec = Util::unpack($json);
        $spec['image_ids'] = array_map(fn($id) => $images[$id],$spec['image_ids']);
        $spec['default_image_id'] = $images[$spec['default_image_id']];
        unset($spec['traffic_gib'],$spec['arch']);
        return Util::json($spec);
    }

    private static function dropIndex(string $table,string $name): void
    {
        $indexes = DB::select('SHOW INDEX FROM mod_whmpve_'.$table);
        if (array_filter($indexes,fn($index) => $index->Key_name===$name)) {
            DB::schema()->table('mod_whmpve_'.$table,fn($t) => $t->dropIndex($name));
        }
    }
}
