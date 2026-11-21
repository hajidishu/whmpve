<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Addons
{
    public const LIMITS=['traffic_reset'=>100,'cores'=>128,'memory_mib'=>1048576,'ipv4'=>16,'ipv6'=>16];
    public const TERMS=['Monthly'=>1,'Quarterly'=>3,'Semi-Annually'=>6,'Annually'=>12,'Biennially'=>24,'Triennially'=>36];

    public static function priceTerms(string $kind): array
    {
        return $kind==='traffic_reset' ? ['once'] : array_keys(self::TERMS);
    }

    public static function priceOptions(string $kind,mixed $price,bool $startingFree): array
    {
        if (is_array($price)) { return $price; }
        if ($price===null) { return []; }
        $terms=$startingFree && $kind!=='traffic_reset' ? ['Monthly'] : self::priceTerms($kind);
        return array_fill_keys($terms,$price);
    }

    public static function migrate(): void
    {
        $plans=DB::table('mod_whmpve_plans')->whereNotNull('traffic_reset_prices')->pluck('id');
        foreach ($plans as $id) {
            DB::connection()->transaction(function() use($id) {
                $plan=DB::table('mod_whmpve_plans')->where('id',$id)->lockForUpdate()->first();
                if (!$plan) { return; }
                $prices=Util::unpack($plan->traffic_reset_prices);
                if ($prices) {
                    DB::table('mod_whmpve_addons')->insert(['server_id'=>$plan->server_id,'plan_id'=>$id,'name'=>Lang::text('ui.traffic_reset_pack'),
                        'kind'=>'traffic_reset','quantity'=>100,'prices'=>Util::json($prices),'created_at'=>Util::now(),'updated_at'=>Util::now()]);
                }
                DB::table('mod_whmpve_plans')->where('id',$id)->update(['traffic_reset_prices'=>null]);
            });
        }
    }

    public static function rows(int $server,int $plan): array
    {
        Util::scoped('plans',$plan,$server);
        return DB::table('mod_whmpve_addons')->where('server_id',$server)->where('plan_id',$plan)->orderBy('id')->get()->map(function($row) {
            return ['prices'=>Util::unpack($row->prices)]+(array)$row;
        })->all();
    }

    public static function save(array $input,int $server): int
    {
        $plan=(int)($input['plan_id'] ?? 0); $id=(int)($input['id'] ?? 0);
        $kind=$input['kind'] ?? '';
        if (!is_string($kind) || !isset(self::LIMITS[$kind])) { throw Fault::localized('message.select_an_add_on_type'); }
        $name=Util::label((string)($input['name'] ?? ''));
        $quantity=Util::integer($input['quantity'] ?? ($kind==='traffic_reset' ? 100 : null),1,self::LIMITS[$kind],Lang::text('message.add_on_quantity_or_reset_percentage'));
        $stock=$input['stock'] ?? '';
        $stock=$stock==='' ? null : Util::integer($stock,0,100000000,Lang::text('ui.stock'));
        $prices=Purchases::prices($input['prices'] ?? [],$kind);
        return DB::connection()->transaction(function() use($input,$server,$plan,$id,$kind,$name,$quantity,$stock,$prices) {
            Ipam::lockServer($server);
            Util::scoped('plans',$plan,$server);
            $pool=null;
            if ($family=self::family($kind)) {
                $pool=Util::scoped('ip_pools',(int)($input['pool_id'] ?? 0),$server);
                if ((int)$pool['family']!==$family) { throw Fault::localized('message.the_ip_pool_does_not_match_the'); }
            }
            $values=['server_id'=>$server,'plan_id'=>$plan,'name'=>$name,'kind'=>$kind,'quantity'=>$quantity,
                'pool_id'=>$pool['id'] ?? null,'stock'=>$stock,'prices'=>Util::json($prices),
                'active'=>!empty($input['active']),'updated_at'=>Util::now()];
            if (!$id) { return DB::table('mod_whmpve_addons')->insertGetId($values+['created_at'=>Util::now()]); }
            $row=DB::table('mod_whmpve_addons')->where('id',$id)->where('server_id',$server)->where('plan_id',$plan);
            if (!$row->lockForUpdate()->first(['id'])) { throw Fault::localized('message.add_on_not_found',404); }
            $row->update($values);
            return $id;
        });
    }

    public static function family(string $kind): int
    {
        return match($kind) {'ipv4'=>4,'ipv6'=>6,default=>0};
    }

    public static function delete(int $server,int $plan,int $id): bool
    {
        Util::scoped('plans',$plan,$server);
        if (!DB::table('mod_whmpve_addons')->where('server_id',$server)->where('plan_id',$plan)->where('id',$id)->delete()) {
            throw Fault::localized('message.add_on_not_found',404);
        }
        return true;
    }

    public static function effect(object $addon,object $service,?string $cycle=null): array
    {
        $effect=['name'=>$addon->name,'kind'=>$addon->kind,'quantity'=>(int)$addon->quantity,'pool_id'=>(int)$addon->pool_id,'cycle'=>null];
        if ($addon->kind!=='traffic_reset') {
            $effect['service_cycle']=$service->billingcycle;
            $effect['cycle']=$cycle ?? ($service->billingcycle==='Free Account' ? 'Monthly' : $service->billingcycle);
        }
        return $effect;
    }

    public static function reason(array $instance,object $service,array $spec,array $effect,?array $usage): ?string
    {
        if (empty($instance['vmid'])) { return Lang::text('message.the_service_has_not_been_provisioned_yet'); }
        $kind=$effect['kind'];
        if ($kind==='traffic_reset') {
            if (!in_array($instance['lifecycle'],['active','suspended'],true) || !in_array($service->domainstatus,['Active','Suspended'],true)) {
                return Lang::text('message.purchases_are_unavailable_for_this_service_status');
            }
            if (($spec['traffic_gb'] ?? 0)<=0) { return Lang::text('message.this_vps_has_unlimited_traffic'); }
            if ($usage!==null && $usage['incoming']+$usage['outgoing']<=0) { return Lang::text('message.no_traffic_has_been_used_this_period'); }
            return null;
        }
        if ($instance['lifecycle']!=='active' || $service->domainstatus!=='Active') { return Lang::text('message.the_service_must_be_active'); }
        $cycle=$effect['cycle'];
        if (!isset(self::TERMS[$cycle]) || ($effect['service_cycle'] ?? $cycle)!==$service->billingcycle) {
            return Lang::text('message.this_add_on_is_unavailable_for_the');
        }
        if ($service->billingcycle!=='Free Account' && ($cycle!==$service->billingcycle || !strtotime((string)$service->nextduedate)
            || $service->nextduedate==='0000-00-00')) { return Lang::text('message.this_add_on_is_unavailable_for_the'); }
        $current=$spec[$kind] ?? $spec[$kind.'_count'] ?? 0;
        return $current+$effect['quantity']>self::LIMITS[$kind] ? Lang::text('message.this_service_has_reached_its_resource_limit') : null;
    }

    public static function firstDue(string $cycle,int $paid): string
    {
        $date=(new \DateTimeImmutable('@'.$paid))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $month=$date->modify('first day of this month')->modify('+'.self::TERMS[$cycle].' months');
        return $month->setDate((int)$month->format('Y'),(int)$month->format('n'),min((int)$date->format('j'),(int)$month->format('t')))->format('Y-m-d');
    }

    public static function recurring(int $service): array
    {
        $items=DB::table('mod_whmpve_traffic_resets as r')->join('tblhostingaddons as a','a.id','=','r.native_addon_id')
            ->where('r.service_id',$service)->where('a.hostingid',$service)->where('r.state','applied')
            ->whereIn('a.status',['Active','Suspended'])->get(['a.name','a.recurring','a.billingcycle'])->all();
        $initial=DB::table('mod_whmpve_traffic_resets')->where('service_id',$service)->where('period','')->where('state','applied')
            ->whereNull('native_addon_id')->orderBy('id')->first(['amount','effect']);
        if ($initial) {
            $effect=Util::unpack($initial->effect);
            if (($effect['service_cycle'] ?? null)==='Free Account') {
                array_unshift($items,(object)['name'=>$effect['name'],'recurring'=>$initial->amount,'billingcycle'=>$effect['cycle'],'in_base'=>true]);
            }
        }
        return $items;
    }

    public static function terminateBilling(array $instance): void
    {
        $ids=DB::table('mod_whmpve_traffic_resets')->where('service_id',$instance['service_id'])
            ->where('server_id',$instance['server_id'])->where('instance_uuid',$instance['uuid'])->whereNotNull('native_addon_id')->pluck('native_addon_id');
        DB::table('tblhostingaddons')->where('hostingid',$instance['service_id'])->whereIn('id',$ids)
            ->whereIn('status',['Active','Suspended'])->update(['status'=>'Terminated','termination_date'=>date('Y-m-d'),'updated_at'=>Util::now()]);
    }
}
