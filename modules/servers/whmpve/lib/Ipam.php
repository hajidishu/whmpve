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
            throw new Fault('请输入有效的 IPv'.$family.' 地址');
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
            throw new Fault('服务器不存在',404);
        }
    }

    private static function writePool(array $input,int $server): int
    {
        ServerConfig::get($server);
        $id = (int)($input['id'] ?? 0);
        $old = $id ? Util::scoped('ip_pools',$id,$server) : null;
        $family = Util::integer($input['family'] ?? 4,4,6,'地址类型');
        if (!in_array($family,[4,6],true)) { throw new Fault('请选择 IPv4 或 IPv6'); }
        $gateway = trim((string)($input['gateway'] ?? ''));
        $bridge = trim((string)($input['bridge'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/',$bridge)) { throw new Fault('请填写 PVE 中已存在的网桥'); }
        $dns = preg_split('/[\s,，]+/',trim((string)($input['dns'] ?? '')),-1,PREG_SPLIT_NO_EMPTY);
        foreach ($dns as $ip) { if (!filter_var($ip,FILTER_VALIDATE_IP)) { throw new Fault('DNS 请填写 IP 地址'); } }
        $values = ['server_id'=>$server,'name'=>Util::label((string)($input['name'] ?? '')),'family'=>$family,
            'prefix'=>Util::integer($input['prefix'] ?? ($family===4 ? 24 : 64),1,$family===4 ? 32 : 128,'前缀长度'),
            'gateway'=>$gateway==='' ? null : self::address($gateway,$family),'bridge'=>$bridge,
            'vlan'=>empty($input['vlan']) ? null : Util::integer($input['vlan'],1,4094,'VLAN'),
            'dns'=>implode(' ',array_unique($dns)),'mtu'=>Util::integer($input['mtu'] ?? 1500,576,9000,'MTU')];
        if ($old) {
            if ((int)$old['family']!==$family && DB::table('mod_whmpve_ips')->where('pool_id',$id)->exists()) { throw new Fault('请先清空此池的地址再更换类型'); }
            if ((int)$old['family']!==$family && DB::table('mod_whmpve_addons')->where('pool_id',$id)->exists()) {
                throw new Fault('请先移除附加服务对该池的引用');
            }
            if (DB::table('mod_whmpve_ips')->where('pool_id',$id)->where('state','!=','free')->exists()) {
                foreach (['family','prefix','gateway','bridge','vlan','dns','mtu'] as $key) {
                    if ($values[$key]!=$old[$key]) { throw new Fault('请先释放该池已分配的地址，再修改网络设置'); }
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
                throw new Fault('该池仍有已分配地址');
            }
            if (DB::table('mod_whmpve_addons')->where('server_id',$server)->where('pool_id',$id)->exists()) {
                throw new Fault('请先移除附加服务对该池的引用');
            }
            foreach (['plans','instances'] as $table) {
                foreach (DB::table('mod_whmpve_'.$table)->where('server_id',$server)->get() as $row) {
                    $spec = Util::unpack($table==='plans' ? $row->spec : ($row->applied ?: $row->desired));
                    if (in_array($id,$spec['ip_pool_ids'] ?? [],true) && ($table==='plans' || $row->lifecycle!=='terminated')) {
                        throw new Fault('请先解除 Plan 或实例对该池的引用');
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
            if ($end<$start || $end-$start>=65536) { throw new Fault('每次最多添加 65536 个连续地址'); }
            for ($number=$start;$number<=$end;$number++) { $addresses[] = long2ip($number); }
        } elseif ($mode==='generate' && (int)$pool['family']===6) {
            $parts = explode('/',trim((string)($input['subnet'] ?? '')));
            if (count($parts)!==2) { throw new Fault('填写 IPv6 前缀，例如 2001:db8:1::/64'); }
            $base = inet_pton(self::address($parts[0],6));
            $prefix = Util::integer($parts[1],1,128,'IPv6 前缀长度');
            if ($prefix>(int)$pool['prefix']) { throw new Fault('生成前缀不能比地址池前缀更长'); }
            $mask = '';
            for ($i=0;$i<16;$i++) { $mask .= chr(max(0,min(255,256-(1 << max(0,min(8,8*($i+1)-$prefix)))))); }
            $base = $base & $mask;
            $count = Util::integer($input['count'] ?? 50,1,4096,'IPv6 数量');
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
        } else { throw new Fault('地址添加方式无效'); }
        if (!$addresses || count($addresses)>65536) { throw new Fault('请填写需要添加的地址'); }
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
                if (count($rows)<$needed) { throw new Fault('所选 IPv'.$family.' 池的可用地址不足'); }
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
        if (count($ips)<$effect['quantity']) { throw new Fault('可用 IP 地址不足',409); }
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
        if (count($rows)!==count($addresses)) { throw new Fault('请填写此服务器 IP 池中的地址'); }
        foreach ($rows as $ip) {
            if ($invoice && $ip['state']==='held' && (int)$ip['hold_invoice_id']===$invoice) { continue; }
            if ($ip['state']!=='free' && ((int)$ip['service_id']!==(int)$instance['service_id'] || !in_array($ip['state'],['reserved','assigned'],true))) {
                throw new Fault('IP 地址已被占用：'.$ip['address'],409);
            }
        }
        if ($reserve) {
            DB::table('mod_whmpve_ips')->whereIn('id',array_column($rows,'id'))->where('state','free')
                ->update(['state'=>'reserved','service_id'=>$instance['service_id'],'updated_at'=>Util::now()]);
        }
        return $rows;
    }
}
