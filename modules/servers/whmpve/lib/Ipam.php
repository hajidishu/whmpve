<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Ipam
{
    public static function address(string $value,int $family): string
    {
        $value = trim($value);
        if (!filter_var($value,FILTER_VALIDATE_IP,$family===4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) {
            throw Fault::localized('message.invalid_ip_family_address',422,['family'=>$family]);
        }
        return inet_ntop(inet_pton($value));
    }

    public static function savePool(array $input): int
    {
        $server = (int)($input['server_id'] ?? 0);
        return DB::connection()->transaction(function() use($input,$server) {
            self::lockServer($server);
            return self::writePool($input,$server);
        });
    }

    public static function lockServer(int $server): void
    {
        if (!DB::table('mod_whmpve_servers')->where('id',$server)->lockForUpdate()->first(['id'])) {
            throw Fault::localized('message.server_not_found',404);
        }
    }

    private static function writePool(array $input,int $server): int
    {
        ServerConfig::get($server);
        $id = (int)($input['id'] ?? 0);
        $old = $id ? Util::scoped('ip_pools',$id,$server) : null;
        $family = Util::integer($input['family'] ?? 4,4,6,Lang::text('ui.address_type'));
        if (!in_array($family,[4,6],true)) { throw Fault::localized('message.select_ipv4_or_ipv6'); }
        $gateway = trim((string)($input['gateway'] ?? ''));
        $bridge = trim((string)($input['bridge'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/',$bridge)) { throw Fault::localized('message.enter_an_existing_pve_bridge'); }
        $dns = preg_split('/[\s,，]+/',trim((string)($input['dns'] ?? '')),-1,PREG_SPLIT_NO_EMPTY);
        foreach ($dns as $ip) { if (!filter_var($ip,FILTER_VALIDATE_IP)) { throw Fault::localized('message.enter_ip_addresses_for_dns_servers'); } }
        $values = ['server_id'=>$server,'name'=>Util::label((string)($input['name'] ?? '')),'family'=>$family,
            'prefix'=>Util::integer($input['prefix'] ?? ($family===4 ? 24 : 64),1,$family===4 ? 32 : 128,Lang::text('ui.prefix_length')),
            'gateway'=>$gateway==='' ? null : self::address($gateway,$family),'bridge'=>$bridge,
            'vlan'=>empty($input['vlan']) ? null : Util::integer($input['vlan'],1,4094,'VLAN'),
            'dns'=>implode(' ',array_unique($dns)),'mtu'=>Util::integer($input['mtu'] ?? 1500,576,9000,'MTU')];
        if ($old) {
            if ((int)$old['family']!==$family && DB::table('mod_whmpve_ips')->where('pool_id',$id)->exists()) { throw Fault::localized('message.remove_the_addresses_in_this_pool_before'); }
            if ((int)$old['family']!==$family && DB::table('mod_whmpve_addons')->where('pool_id',$id)->exists()) {
                throw Fault::localized('message.remove_references_to_this_pool_from_add');
            }
            if (DB::table('mod_whmpve_ips')->where('pool_id',$id)->where('state','!=','free')->exists()) {
                foreach (['family','prefix','gateway','bridge','vlan','dns','mtu'] as $key) {
                    if ($values[$key]!=$old[$key]) { throw Fault::localized('message.release_this_pools_assigned_addresses_before_changing'); }
                }
            }
            DB::table('mod_whmpve_ip_pools')->where('id',$id)->update($values+['updated_at'=>Util::now()]);
            DB::table('mod_whmpve_ips')->where('pool_id',$id)->update(['prefix'=>$values['prefix'],'gateway'=>$values['gateway']]);
            return $id;
        }
        return DB::table('mod_whmpve_ip_pools')->insertGetId($values+['created_at'=>Util::now(),'updated_at'=>Util::now()]);
    }

    public static function saveWithAddresses(array $input): array
    {
        return DB::connection()->transaction(function() use($input) {
            $id=self::savePool($input);
            return ['id'=>$id]+(empty($input['mode']) ? [] : self::add(['pool_id'=>$id]+$input));
        });
    }

    public static function removePool(int $id,int $server): void
    {
        DB::connection()->transaction(function() use($id,$server) {
            self::lockServer($server);
            Util::scoped('ip_pools',$id,$server);
            if (DB::table('mod_whmpve_ips')->where('pool_id',$id)->where('state','!=','free')->exists()) {
                throw Fault::localized('message.this_pool_still_has_assigned_addresses');
            }
            if (DB::table('mod_whmpve_addons')->where('server_id',$server)->where('pool_id',$id)->exists()) {
                throw Fault::localized('message.remove_references_to_this_pool_from_add');
            }
            foreach (['plans','instances'] as $table) {
                foreach (DB::table('mod_whmpve_'.$table)->where('server_id',$server)->get() as $row) {
                    $spec = Util::unpack($table==='plans' ? $row->spec : ($row->applied ?: $row->desired));
                    if (in_array($id,$spec['ip_pool_ids'] ?? [],true) && ($table==='plans' || $row->lifecycle!=='terminated')) {
                        throw Fault::localized('message.remove_references_to_this_pool_from_plans');
                    }
                }
            }
            DB::table('mod_whmpve_ips')->where('pool_id',$id)->delete();
            DB::table('mod_whmpve_ip_pools')->where('id',$id)->delete();
        });
    }

    private static function usable(string $address,array $pool): bool
    {
        if ($address===$pool['gateway']) { return false; }
        if ((int)$pool['family']===6) { return true; }
        $prefix = (int)$pool['prefix'];
        if ($prefix>=31) { return true; }
        $number = (int)sprintf('%u',ip2long($address));
        $mask = (0xffffffff << (32-$prefix)) & 0xffffffff;
        return ($number & ~$mask)!==0 && ($number | $mask)!==0xffffffff;
    }

    public static function add(array $input): array
    {
        $server=(int)($input['server_id'] ?? 0);
        return DB::connection()->transaction(function() use($input,$server) {
            self::lockServer($server);
            $pool=Util::scoped('ip_pools',(int)($input['pool_id'] ?? 0),$server);
            return self::addToPool($input,$pool);
        });
    }

    private static function addToPool(array $input,array $pool): array
    {
        $addresses = [];
        $mode = $input['mode'] ?? 'manual';
        if ($mode==='manual') {
            foreach (preg_split('/[\s,，]+/',trim((string)($input['addresses'] ?? '')),-1,PREG_SPLIT_NO_EMPTY) as $value) {
                $addresses[] = self::address($value,(int)$pool['family']);
            }
        } elseif ($mode==='range' && (int)$pool['family']===4) {
            $start = (int)sprintf('%u',ip2long(self::address((string)($input['start'] ?? ''),4)));
            $end = (int)sprintf('%u',ip2long(self::address((string)($input['end'] ?? ''),4)));
            if ($end<$start || $end-$start>=65536) { throw Fault::localized('message.add_up_to_65536_consecutive_addresses_at'); }
            for ($number=$start;$number<=$end;$number++) { $addresses[] = long2ip($number); }
        } elseif ($mode==='generate' && (int)$pool['family']===6) {
            $parts = explode('/',trim((string)($input['subnet'] ?? '')));
            if (count($parts)!==2) { throw Fault::localized('message.enter_an_ipv6_prefix_for_example_2001'); }
            $base = inet_pton(self::address($parts[0],6));
            $prefix = Util::integer($parts[1],1,128,Lang::text('ui.ipv6_prefix_length'));
            if ($prefix>(int)$pool['prefix']) { throw Fault::localized('message.the_generated_prefix_cannot_be_longer_than'); }
            $mask = '';
            for ($i=0;$i<16;$i++) { $mask .= chr(max(0,min(255,256-(1 << max(0,min(8,8*($i+1)-$prefix)))))); }
            $base = $base & $mask;
            $count = Util::integer($input['count'] ?? 50,1,4096,Lang::text('ui.ipv6_count'));
            $continuous = !empty($input['continuous']);
            $cursor = $base;
            $seen = [];
            for ($tries=0;count($addresses)<$count && $tries<max(8192,$count*4);$tries++) {
                if ($continuous) {
                    $cursor = self::increment($cursor);
                    if (($cursor & $mask)!==$base) { break; }
                    $binary = $cursor;
                } else { $binary = $base | (random_bytes(16) & ~$mask); }
                if ($binary===$base && $prefix<128) { continue; }
                $address = inet_ntop($binary);
                if (isset($seen[$address])) { continue; }
                $seen[$address] = true;
                $addresses[] = $address;
            }
        } else { throw Fault::localized('message.invalid_address_entry_method'); }
        if (!$addresses || count($addresses)>65536) { throw Fault::localized('message.enter_the_addresses_to_add'); }
        $added = 0;
        foreach (array_chunk(array_values(array_unique($addresses)),500) as $chunk) {
            $rows = [];
            foreach ($chunk as $address) {
                if (!self::usable($address,$pool)) { continue; }
                $rows[] = ['server_id'=>$pool['server_id'],'pool_id'=>$pool['id'],'family'=>$pool['family'],'address'=>$address,
                    'prefix'=>$pool['prefix'],'gateway'=>$pool['gateway'],'state'=>'free','created_at'=>Util::now(),'updated_at'=>Util::now()];
            }
            if ($rows) { $added += DB::table('mod_whmpve_ips')->insertOrIgnore($rows); }
        }
        return ['added'=>$added,'skipped'=>count($addresses)-$added];
    }

    private static function increment(string $binary): string
    {
        for ($i=strlen($binary)-1;$i>=0;$i--) {
            $value = ord($binary[$i]); $binary[$i] = chr(($value+1)&255);
            if ($value!==255) { break; }
        }
        return $binary;
    }

    public static function reserve(array $instance,array $spec): void
    {
        DB::connection()->transaction(function() use($instance,$spec) {
            self::lockServer((int)$instance['server_id']);
            foreach ([4,6] as $family) {
                $needed = (int)$spec['ipv'.$family.'_count'];
                $existing = DB::table('mod_whmpve_ips')->where('service_id',$instance['service_id'])->where('family',$family)->count();
                $needed -= $existing;
                if ($needed<=0) { continue; }
                $pools = DB::table('mod_whmpve_ip_pools')->where('server_id',$instance['server_id'])->where('family',$family)
                    ->whereIn('id',$spec['ip_pool_ids'])->pluck('id');
                $rows = DB::table('mod_whmpve_ips')->where('server_id',$instance['server_id'])->whereIn('pool_id',$pools)
                    ->where('state','free')->orderBy('id')->limit($needed)->lockForUpdate()->get();
                if (count($rows)<$needed) { throw Fault::localized('message.ip_pool_exhausted',422,['family'=>$family]); }
                DB::table('mod_whmpve_ips')->whereIn('id',$rows->pluck('id'))->update([
                    'service_id'=>$instance['service_id'],'state'=>'reserved','updated_at'=>Util::now()]);
            }
        });
    }

    public static function assigned(array $instance): array
    {
        return DB::table('mod_whmpve_ips')->where('server_id',$instance['server_id'])->where('service_id',$instance['service_id'])
            ->where('state','assigned')->orderBy('id')->pluck('address')->all();
    }

    public static function freeForAddon(int $server,array $effect)
    {
        return DB::table('mod_whmpve_ips')->where('server_id',$server)->where('pool_id',$effect['pool_id'])
            ->where('family',Addons::family($effect['kind']))->where('state','free');
    }

    public static function holdForAddon(int $server,array $effect,int $invoice): array
    {
        $ips=self::freeForAddon($server,$effect)->orderBy('id')->limit($effect['quantity'])->lockForUpdate()->get(['id','address']);
        if (count($ips)<$effect['quantity']) { throw Fault::localized('message.not_enough_free_ip_addresses',409); }
        self::freeForAddon($server,$effect)->whereIn('id',$ips->pluck('id'))->update([
            'state'=>'held','hold_invoice_id'=>$invoice,'updated_at'=>Util::now(),
        ]);
        return $ips->pluck('address')->all();
    }

    public static function addonHolds(int $server,int $invoice)
    {
        return DB::table('mod_whmpve_ips')->where('server_id',$server)->where('hold_invoice_id',$invoice)->where('state','held');
    }

    public static function editAddresses(array $instance,array $addresses,bool $reserve=false,int $invoice=0): array
    {
        if ($reserve) { self::lockServer((int)$instance['server_id']); }
        $query=DB::table('mod_whmpve_ips as i')->join('mod_whmpve_ip_pools as p','p.id','=','i.pool_id')
            ->where('i.server_id',$instance['server_id'])->where('p.server_id',$instance['server_id'])
            ->whereIn('i.address',$addresses)->orderBy('i.id');
        if ($reserve) { $query->lockForUpdate(); }
        $rows=$query->get(['i.*','p.bridge','p.vlan','p.dns','p.mtu'])->map(fn($row)=>(array)$row)->all();
        if (count($rows)!==count($addresses)) { throw Fault::localized('message.enter_addresses_from_this_servers_ip_pools'); }
        foreach ($rows as $ip) {
            if ($invoice && $ip['state']==='held' && (int)$ip['hold_invoice_id']===$invoice) { continue; }
            if ($ip['state']!=='free' && ((int)$ip['service_id']!==(int)$instance['service_id'] || !in_array($ip['state'],['reserved','assigned'],true))) {
                throw new Fault(Lang::text('message.ip_address_already_in_use').$ip['address'],409);
            }
        }
        if ($reserve) {
            DB::table('mod_whmpve_ips')->whereIn('id',array_column($rows,'id'))->where('state','free')
                ->update(['state'=>'reserved','service_id'=>$instance['service_id'],'updated_at'=>Util::now()]);
        }
        return $rows;
    }
}
