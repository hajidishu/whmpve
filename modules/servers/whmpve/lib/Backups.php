<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Backups
{
    public static function policy(array $instance): array
    {
        $plan=Util::scoped('plans',(int)$instance['plan_id'],(int)$instance['server_id']);
        $spec=Util::unpack($plan['spec']);
        $limit=(int)($spec['backup_limit'] ?? 0);
        return ['limit'=>$limit,'automatic_allowed'=>$limit>0 && !empty($spec['auto_backup'])];
    }

    public static function records(array $instance)
    {
        return DB::table('mod_whmpve_backups')->where('server_id',$instance['server_id'])->where('service_id',$instance['service_id']);
    }

    public static function record(array $instance,int $id): array
    {
        return (array)(self::records($instance)->where('id',$id)->first() ?? throw new Fault('备份不存在',404));
    }

    public static function summary(array $instance): array
    {
        $policy=self::policy($instance);
        $policy['automatic_allowed']=$policy['automatic_allowed'] && array_key_exists('auto_backup_since',$instance);
        return $policy+['automatic_enabled'=>$policy['automatic_allowed'] && !empty($instance['auto_backup_since']),
            'items'=>self::records($instance)->orderByDesc('id')
            ->get(['id','size','automatic','created_at'])->map(fn($row)=>(array)$row)->all()];
    }

    public static function setAutomatic(int $service,int $client,bool $enabled): array
    {
        if (!Util::enabled()) { throw new Fault('管理插件尚未激活或已停用',503); }
        return DB::connection()->transaction(function() use($service,$client,$enabled) {
            $instances=DB::table('mod_whmpve_instances')->where('service_id',$service);
            $instance=$instances->lockForUpdate()->first();
            $native=DB::table('tblhosting')->where('id',$service)->where('userid',$client)->lockForUpdate()->first(['domainstatus','server']);
            if (!$native || !$instance) { throw new Fault('服务不存在',404); }
            if (empty($instance->vmid) || !in_array($native->domainstatus,['Active','Suspended'],true)
                || !in_array($instance->lifecycle,['active','suspended'],true)) { throw new Fault('当前服务状态不允许设置自动备份',403); }
            Catalog::assertServerBinding((array)$instance,(int)$native->server);
            if (!property_exists($instance,'auto_backup_since')) { throw new Fault('请先升级插件数据库',503); }
            if ($enabled && !self::policy((array)$instance)['automatic_allowed']) { throw new Fault('此套餐未开放自动备份',403); }
            if ((bool)$instance->auto_backup_since!==$enabled) {
                $instances->update(['auto_backup_since'=>$enabled ? Util::now() : null]);
            }
            return ['automatic_enabled'=>$enabled];
        });
    }

    public static function note(array $instance,int $task): string
    {
        return Pve::marker($instance).':backup:'.$task;
    }

    public static function path(Pve $pve,string $storage): string
    {
        return '/nodes/'.rawurlencode($pve->settings['node']).'/storage/'.rawurlencode($storage).'/content';
    }

    public static function archive(Pve $pve,array $instance,array $record): ?array
    {
        $storage=Disks::storage(explode(':',$record['volume'],2)[0]);
        $rows=$pve->request('GET',self::path($pve,$storage),['content'=>'backup','vmid'=>$record['source_vmid']]);
        if (!is_array($rows) || !array_is_list($rows)) { throw new Fault('PVE 备份列表无效',502); }
        foreach ($rows as $row) {
            if (($row['volid'] ?? '')!==$record['volume']) { continue; }
            if (($row['notes'] ?? '')!==self::note($instance,(int)$record['task_id'])) {
                throw new IdentityMismatch('PVE 备份身份不匹配',409);
            }
            return $row;
        }
        return null;
    }

    public static function checkCreate(array $instance,bool $automatic,?string $cutoff=null): bool
    {
        $policy=self::policy($instance);
        if ($automatic) {
            return $cutoff!==null && $policy['automatic_allowed'] && array_key_exists('auto_backup_since',$instance)
                && DB::table('mod_whmpve_instances')->where('id',$instance['id'])->where('auto_backup_since','<=',$cutoff)->exists()
                && self::records($instance)->where('automatic',0)->count()<$policy['limit'];
        }
        if (!$policy['limit']) { throw new Fault('此套餐未启用备份'); }
        if (self::records($instance)->count()>=$policy['limit']) { throw new Fault('备份数量已达上限，请先删除一份备份',409); }
        return true;
    }

    public static function schedule(?int $time=null,?int $server=null): void
    {
        $now=(new \DateTimeImmutable('@'.($time ?? time())))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $due=$now->setTime(3,0);
        if ($now<$due) { return; }
        static $ready=false;
        if (!$ready && !($ready=DB::schema()->hasColumn('mod_whmpve_instances','auto_backup_since'))) { return; }
        $key='auto-backup-'.$now->format('Y-m-d');
        $cutoff=gmdate('Y-m-d H:i:s',$due->getTimestamp());
        $query=DB::table('mod_whmpve_instances as i')->join('mod_whmpve_plans as p',function($join) {
            $join->on('p.id','=','i.plan_id')->on('p.server_id','=','i.server_id');
        })->join('mod_whmpve_servers as s','s.id','=','i.server_id')->join('tblhosting as h','h.id','=','i.service_id')
            ->where('i.lifecycle','active')->whereNotNull('i.vmid')->where('h.domainstatus','Active')->where('s.active',1)
            ->where('i.auto_backup_since','<=',$cutoff)
            ->where('p.updated_at','<=',$cutoff)->where('i.created_at','<=',$cutoff)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(p.spec, '$.auto_backup')) = 'true'")
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(p.spec, '$.backup_limit')) AS UNSIGNED) > 0");
        if ($server) { $query->where('i.server_id',$server); }
        foreach ($query->pluck('i.service_id') as $service) {
            if (DB::table('mod_whmpve_tasks')->where('request_key',hash('sha256',$service.':backup:'.$key))->exists()) { continue; }
            try { Jobs::enqueue((int)$service,'backup',$key,['automatic'=>true,'cutoff'=>$cutoff],'backup-scheduler'); }
            catch (Fault $e) { if (!in_array($e->http,[403,409],true)) { throw $e; } }
        }
    }
}
