<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Traffic
{
    public const REASON = 'WHMPVE: monthly traffic quota';

    public static function quota(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || $value<0 || $value>100000000) {
            throw Fault::localized('message.enter_a_monthly_traffic_quota_between_0');
        }
        return (float)$value;
    }

    public static function row(int $service): ?array
    {
        $row=DB::table('mod_whmpve_traffic')->where('service_id',$service)->first();
        return $row ? (array)$row : null;
    }

    public static function cycle(int $day,?int $time=null): array
    {
        $date=(new \DateTimeImmutable('@'.($time ?? time())))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $month=$date->modify('first day of this month')->setTime(0,0);
        $boundary=static fn(\DateTimeImmutable $first)=>$first->setDate(
            (int)$first->format('Y'),(int)$first->format('n'),min($day,(int)$first->format('t')));
        if ($date<$boundary($month)) { $month=$month->modify('-1 month'); }
        $start=$boundary($month);
        return ['period'=>$start->format('Y-m'),'start'=>$start->getTimestamp(),
            'end'=>$boundary($month->modify('+1 month'))->getTimestamp()];
    }

    public static function anchorDay(int $service,?array $row=null): int
    {
        $row ??= self::row($service);
        if (!empty($row['anchor_day'])) { return (int)$row['anchor_day']; }
        $native=DB::table('tblhosting as h')->leftJoin('tblorders as o','o.id','=','h.orderid')
            ->where('h.id',$service)->first(['o.date as order_date','h.regdate']);
        foreach ([$native->order_date ?? '',$native->regdate ?? ''] as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/',(string)$date,$parts)
                && checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) { return (int)$parts[3]; }
        }
        return 1;
    }

    public static function migrateCycles(): void
    {
        $now=time();
        DB::table('mod_whmpve_traffic')->whereNull('anchor_day')->chunkById(250,function($rows) use($now) {
            foreach ($rows as $row) {
                $day=self::anchorDay((int)$row->service_id,(array)$row);
                DB::table('mod_whmpve_traffic')->where('id',$row->id)->whereNull('anchor_day')->update([
                    'anchor_day'=>$day,'period'=>self::cycle($day,$now)['period'],
                ]);
            }
        });
    }

    public static function bind(array $instance): void
    {
        $service=(int)$instance['service_id'];
        $values=['last_vmid'=>$instance['vmid'],'last_in'=>0,'last_out'=>0,'last_uptime'=>0,'sample_at'=>time(),'updated_at'=>Util::now()];
        if (self::row($service)) { DB::table('mod_whmpve_traffic')->where('service_id',$service)->update($values); }
        else {
            $day=self::anchorDay($service);
            DB::table('mod_whmpve_traffic')->insert($values+['service_id'=>$service,'anchor_day'=>$day,
                'period'=>self::cycle($day)['period'],'created_at'=>Util::now()]);
        }
    }

    public static function record(array $instance,array $current,?int $at=null): array
    {
        $at ??= time(); $service=(int)$instance['service_id'];
        return DB::connection()->transaction(function() use($instance,$current,$at,$service) {
            $row=DB::table('mod_whmpve_traffic')->where('service_id',$service)->lockForUpdate()->first();
            $day=self::anchorDay($service,$row ? (array)$row : null);
            $cycle=self::cycle($day,$at);
            $in=max(0,(int)($current['netin'] ?? 0)); $out=max(0,(int)($current['netout'] ?? 0));
            $uptime=max(0,(int)($current['uptime'] ?? 0)); $running=($current['status'] ?? '')==='running';
            $incoming=0; $outgoing=0;
            if ($row) {
                $same=(int)$row->last_vmid===(int)$instance['vmid'] && $row->last_vmid!==null;
                $reset=!$same || $in<(int)$row->last_in || $out<(int)$row->last_out
                    || ($row->last_uptime>0 && abs(($at-$uptime)-($row->sample_at-$row->last_uptime))>15);
                if ($running) {
                    $incoming=$reset ? $in : max(0,$in-(int)$row->last_in);
                    $outgoing=$reset ? $out : max(0,$out-(int)$row->last_out);
                }
                if ($row->anchor_day!==null && $row->period!==$cycle['period']) {
                    $fraction=max(0,min(1,($at-$cycle['start'])/max(1,$at-(int)$row->sample_at)));
                    $incoming=(int)round($incoming*$fraction); $outgoing=(int)round($outgoing*$fraction);
                } else { $incoming+=(int)$row->incoming; $outgoing+=(int)$row->outgoing; }
            }
            $values=['period'=>$cycle['period'],'anchor_day'=>$day,'incoming'=>$incoming,'outgoing'=>$outgoing,
                'last_in'=>$running ? $in : 0,'last_out'=>$running ? $out : 0,'last_vmid'=>$running ? $instance['vmid'] : null,
                'last_uptime'=>$running ? $uptime : 0,'sample_at'=>$at,'updated_at'=>Util::now()];
            if ($row) { DB::table('mod_whmpve_traffic')->where('service_id',$service)->update($values); }
            else { DB::table('mod_whmpve_traffic')->insert($values+['service_id'=>$service,'created_at'=>Util::now()]); }
            return self::row($service);
        });
    }

    public static function sample(array $instance,Pve $pve,?array $current=null): array
    {
        if ($current===null) {
            $owned=$pve->owned($instance) ?: throw Fault::localized('message.could_not_find_the_instance_for_traffic');
            $current=$pve->request('GET',$owned['path'].'/status/current');
        }
        return self::record($instance,$current);
    }

    public static function target(array $spec,?array $row): string
    {
        if (!$row || (float)($spec['traffic_gb'] ?? 0)<=0
            || (!empty($row['anchor_day']) && $row['period']!==self::cycle((int)$row['anchor_day'])['period'])) { return 'normal'; }
        $used=(int)$row['incoming']+(int)$row['outgoing'];
        return $used>=(float)$spec['traffic_gb']*1000000000 ? (($spec['traffic_action'] ?? 'throttle')==='suspend' ? 'suspended' : 'throttled') : 'normal';
    }

    public static function speed(array $spec,?array $row): float
    {
        $speed=(float)$spec['bandwidth_mbps'];
        if (self::target($spec,$row)==='throttled') {
            $limited=(float)($spec['traffic_speed_kbps'] ?? 256)/1000;
            $speed=$speed>0 ? min($speed,$limited) : $limited;
        }
        return $speed;
    }

    public static function summary(int $service,array $spec): array
    {
        $row=self::row($service); $cycle=self::cycle(self::anchorDay($service,$row));
        $current=$row && (empty($row['anchor_day']) || $row['period']===$cycle['period']);
        return ['incoming'=>$current ? (int)$row['incoming'] : 0,'outgoing'=>$current ? (int)$row['outgoing'] : 0,
            'limit_gb'=>(float)($spec['traffic_gb'] ?? 0),'reset_at'=>$cycle['end'],
            'restriction'=>$row['restriction'] ?? 'normal','action'=>$spec['traffic_action'] ?? 'throttle',
            'speed_kbps'=>$spec['traffic_speed_kbps'] ?? 256,'updated_at'=>(int)($row['sample_at'] ?? 0)];
    }

    public static function dueServers(): array
    {
        return DB::table('mod_whmpve_instances as i')->leftJoin('mod_whmpve_traffic as t','t.service_id','=','i.service_id')
            ->whereNotNull('i.vmid')->whereIn('i.lifecycle',['active','suspended'])
            ->where(fn($q)=>$q->whereNull('t.id')->orWhere('t.sample_at','<',time()-60))
            ->distinct()->pluck('i.server_id')->all();
    }

    public static function poll(int $server,bool $force=false,?float $deadline=null): bool
    {
        $lock='whmpve_server_'.$server;
        $result=DB::select('SELECT GET_LOCK(?,0) as acquired',[$lock]);
        if (empty($result[0]->acquired)) { return false; }
        try { return self::pollLocked($server,$force,$deadline); }
        finally { DB::select('SELECT RELEASE_LOCK(?)',[$lock]); }
    }

    private static function pollLocked(int $server,bool $force,?float $deadline): bool
    {
        $queued=false;
        $query=DB::table('mod_whmpve_instances as i')->leftJoin('mod_whmpve_traffic as t','t.service_id','=','i.service_id')
            ->where('i.server_id',$server)->whereNotNull('i.vmid')->whereIn('i.lifecycle',['active','suspended']);
        if (!$force) { $query->where(fn($q)=>$q->whereNull('t.id')->orWhere('t.sample_at','<',time()-60)); }
        $cursorKey='cron:traffic:'.$server;
        if ($deadline!==null) {
            $cursor=(int)DB::table('mod_whmpve_cache')->where('cache_key',$cursorKey)->value('value');
            $query->orderByRaw('CASE WHEN i.id<=? THEN 1 ELSE 0 END',[$cursor]);
        }
        $rows=$query->orderBy('i.id')->get(['i.*']);
        if (!$rows->count()) { return false; }
        $pve=new Pve($server,$deadline);
        $last=null;
        foreach ($rows as $instance) {
            if ($deadline!==null && microtime(true)>=$deadline) { break; }
            $last=(int)$instance->id;
            $instance=(array)$instance; $service=(int)$instance['service_id'];
            try {
                $row=self::sample($instance,$pve); $spec=Util::unpack($instance['applied'] ?: $instance['desired']);
                $target=self::target($spec,$row);
                if ($target===$row['restriction'] && $target!=='throttled') { continue; }
                if (DB::table('mod_whmpve_tasks')->where('service_id',$service)->whereIn('status',['queued','running'])->exists()) { continue; }
                $reset=(int)($row['reset_invoice'] ?? 0);
                // A repeated transition in the same period needs a new task after the last applied restriction.
                $previous=$target===$row['restriction'] ? 0 : DB::table('mod_whmpve_tasks')->where('service_id',$service)
                    ->whereIn('action',['traffic','traffic_reset'])->where('status','success')->max('id');
                $key='traffic-'.hash('sha256',$row['anchor_day'].':'.$row['period'].':'.$reset.':'.$target.':'.(int)$previous.':'
                    .Util::json([$spec['traffic_gb'],$spec['traffic_action'],$spec['traffic_speed_kbps'],$spec['bandwidth_mbps']]));
                $task=Jobs::enqueue($service,'traffic',$key,[],'traffic');
                if ($task['status']==='failed' && strtotime($task['updated_at'].' UTC')<time()-60) { Jobs::retry((int)$task['id'],'traffic'); }
                $queued=$queued || in_array($task['status'],['queued','running','failed'],true);
            } catch (Pending) { break; }
            catch (\Throwable $e) { Util::log($e->getMessage(),$server,$service); }
        }
        if ($deadline!==null && $last!==null) {
            DB::table('mod_whmpve_cache')->updateOrInsert(['cache_key'=>$cursorKey],[
                'value'=>(string)$last,'expires_at'=>0,'created_at'=>Util::now(),'updated_at'=>Util::now(),
            ]);
        }
        return $queued;
    }
}
