<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class ServerConfig
{
    public const DEFAULT_LIMITS = ['vmid_min'=>100,'vmid_max'=>999999999,'max_instances'=>0];

    public static function get(int $id): array
    {
        $config = Util::row('servers',$id);
        $native = DB::table('tblservers')->where('id',$id)->where('type','whmpve')->first();
        if (!$native) { throw Fault::localized('message.server_not_found',404); }
        return $config + ['name'=>$native->name,'host'=>$native->hostname ?: $native->ipaddress,'port'=>$native->port ?: 8006,
            'token_id'=>$native->username,'has_token'=>!empty($native->password)];
    }

    public static function save(array $input): int
    {
        $id = (int)($input['id'] ?? 0);
        $previous = $id ? self::get($id) : null;
        $old = $previous ? Util::unpack($previous['settings']) : [];
        $name = Util::label((string)($input['name'] ?? ''),64);
        $host = trim((string)($input['host'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9.:-]+$/',$host)) { throw Fault::localized('message.enter_the_pve_address'); }
        $token = trim((string)($input['token_id'] ?? ''));
        if (!preg_match('/^[^\s=!]+@[^\s=!]+![^\s=!]+$/',$token)) { throw Fault::localized('message.token_id_format_user_pve_token'); }
        $secret = (string)($input['token_secret'] ?? '');
        if (!$id && $secret==='') { throw Fault::localized('message.enter_the_api_token_secret'); }
        $settings = [];
        foreach (['node','storage','pve_pool'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/',$value)) { throw new Fault(Lang::text('message.enter').$key); }
            $settings[$key] = $value;
        }
        $settings['backup_storage']=Disks::storage(trim((string)($input['backup_storage'] ?? $old['backup_storage'] ?? '')) ?: 'local');
        foreach (['vmid_min'=>[100,999999999],'vmid_max'=>[100,999999999],'max_instances'=>[0,100000]] as $key=>[$min,$max]) {
            $settings[$key] = Util::integer($input[$key] ?? $old[$key] ?? self::DEFAULT_LIMITS[$key],$min,$max,$key);
        }
        $settings['reserve_memory_mib']=Util::integer($input['reserve_memory_mib'] ?? $old['reserve_memory_mib'] ?? 512,64,1048576,'reserve_memory_mib');
        if ($settings['vmid_max']<$settings['vmid_min']) { throw Fault::localized('message.the_last_vmid_must_be_at_least'); }
        $settings['ca_file'] = trim((string)($input['ca_file'] ?? ''));
        if ($settings['ca_file']!=='' && !is_readable($settings['ca_file'])) { throw Fault::localized('message.the_ca_certificate_path_is_unreadable'); }
        $settings['console_url'] = rtrim(trim((string)($input['console_url'] ?? '')),'/');
        if ($settings['console_url'] && !preg_match('#^wss?://[a-zA-Z0-9.:-]+/console$#',$settings['console_url'])) { throw Fault::localized('message.enter_a_websocket_url_ending_in_console'); }
        $settings['console_user'] = trim((string)($input['console_user'] ?? ''));
        $settings['console_password'] = empty($input['console_password']) ? ($old['console_password'] ?? '') : encrypt($input['console_password']);
        if ($id && DB::table('mod_whmpve_instances')->where('server_id',$id)->where('lifecycle','!=','terminated')->exists()) {
            foreach (['node','storage','pve_pool'] as $key) {
                if (($old[$key] ?? null)!=$settings[$key]) { throw Fault::localized('message.this_server_still_has_instances_its_node'); }
            }
            if ($settings['vmid_min']>($old['vmid_min'] ?? self::DEFAULT_LIMITS['vmid_min'])
                || $settings['vmid_max']<($old['vmid_max'] ?? self::DEFAULT_LIMITS['vmid_max'])) {
                throw Fault::localized('message.this_server_still_has_instances_you_may');
            }
        }
        return DB::connection()->transaction(function () use ($id,$input,$name,$host,$token,$secret,$settings) {
            $previous=$id ? Util::unpack(DB::table('mod_whmpve_servers')->where('id',$id)->lockForUpdate()->value('settings')) : [];
            $settings['onboot']=(bool)($input['onboot'] ?? $previous['onboot'] ?? false);
            $native = ['name'=>$name,'type'=>'whmpve','hostname'=>$host,'ipaddress'=>filter_var($host,FILTER_VALIDATE_IP) ? $host : '',
                'username'=>$token,'port'=>Util::integer($input['port'] ?? 8006,1,65535,Lang::text('message.port')),'secure'=>'on','active'=>1];
            if ($secret!=='') { $native['password'] = encrypt($secret); }
            if ($id) { DB::table('tblservers')->where('id',$id)->update($native); }
            else { $id = DB::table('tblservers')->insertGetId($native); }
            DB::table('mod_whmpve_servers')->updateOrInsert(['id'=>$id],[
                'settings'=>Util::json($settings),'active'=>array_key_exists('active',$input) ? (int)(bool)$input['active'] : 1,
                'created_at'=>DB::table('mod_whmpve_servers')->where('id',$id)->value('created_at') ?? Util::now(),'updated_at'=>Util::now(),
            ]);
            if (!empty($previous['onboot'])!==$settings['onboot']) {
                DB::table('mod_whmpve_cache')->updateOrInsert(['cache_key'=>'onboot:'.$id],['expires_at'=>0,
                    'value'=>Util::json(['revision'=>bin2hex(random_bytes(8)),'cursor'=>0,'retry'=>false]),
                    'created_at'=>Util::now(),'updated_at'=>Util::now()]);
            }
            return $id;
        });
    }

    public static function delete(int $id): void
    {
        self::get($id);
        if (DB::table('mod_whmpve_instances')->where('server_id',$id)->exists()
            || DB::table('tblproducts')->where('servertype','whmpve')->where('configoption1',$id)->exists()) {
            throw Fault::localized('message.this_server_is_linked_to_services_or');
        }
        DB::connection()->transaction(function () use ($id) {
            foreach (['addons','plans','images','ips','ip_pools','image_groups','logs'] as $table) { DB::table('mod_whmpve_'.$table)->where('server_id',$id)->delete(); }
            DB::table('mod_whmpve_servers')->where('id',$id)->delete();
            DB::table('mod_whmpve_cache')->where('cache_key','onboot:'.$id)->delete();
            DB::table('tblservergroupsrel')->where('serverid',$id)->delete();
            DB::table('tblservers')->where('id',$id)->delete();
        });
    }
}
