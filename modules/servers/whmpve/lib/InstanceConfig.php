<?php
declare(strict_types=1);
namespace Whmpve;

final class InstanceConfig
{
    public static function editable(string $lifecycle,string $status,?string $reason): bool
    {
        return ($lifecycle==='active' && $status==='Active')
            || ($lifecycle==='suspended' && $status==='Suspended' && $reason===Traffic::REASON);
    }

    public static function revision(array $instance,array $addresses): string
    {
        sort($addresses,SORT_STRING);
        return hash('sha256',Util::json([$instance['vmid'],$instance['desired'],$instance['applied'],$addresses]));
    }

    public static function afterPackage(array $target,array $baseline,array $effective): array
    {
        foreach (['cores','memory_mib','swap_mib','disk_gib'] as $key) {
            $target[$key]+=max(0,$effective[$key]-($baseline[$key] ?? $effective[$key]));
        }
        $target['cores']=Util::integer($target['cores'],1,128,'CPU 核数');
        $target['memory_mib']=Util::integer($target['memory_mib'],32,1048576,'内存（MiB）');
        $target['swap_mib']=Util::integer($target['swap_mib'],0,1048576,'Swap（MiB）');
        $target['disk_gib']=Disks::size($target['disk_gib']);
        $oldDisks=array_column($baseline['data_disks'] ?? [],'size_gib','id');
        $effectiveDisks=array_column($effective['data_disks'] ?? [],'size_gib','id');
        foreach ($target['data_disks'] as &$disk) {
            $id=$disk['id'];
            $disk['size_gib']=Disks::size($disk['size_gib']+max(0,($effectiveDisks[$id] ?? 0)-($oldDisks[$id] ?? 0)));
        }
        unset($disk);
        foreach (['bandwidth_mbps','cpu_limit'] as $key) {
            if ($effective[$key]!=($baseline[$key] ?? $effective[$key])) { $target[$key]=$effective[$key]; }
        }
        if (($effective['traffic_gb'] ?? 0)!=($baseline['traffic_gb'] ?? 0)) {
            $target['traffic_gb']=$effective['traffic_gb'] ?? 0;
        }
        $target['cpu_limit']=min($target['cpu_limit'],$target['cores']);
        foreach (['ipv4_count','ipv6_count','ip_pool_ids','network'] as $key) {
            if (isset($effective[$key])) { $target[$key]=$effective[$key]; }
        }
        return $target;
    }

    public static function edit(array $input,array $spec): array
    {
        if (array_key_exists('traffic_gb',$input)) { $spec['traffic_gb']=Traffic::quota($input['traffic_gb']); }
        foreach (['cores'=>[1,128,'CPU 核数'],'memory_mib'=>[32,1048576,'内存（MiB）'],
            'bandwidth_mbps'=>[0,100000,'带宽（Mbps）']] as $key=>[$min,$max,$label]) {
            $spec[$key]=Util::integer($input[$key] ?? null,$min,$max,$label);
        }
        $limit=$input['cpu_limit'] ?? null;
        if (!is_numeric($limit) || !is_finite((float)$limit) || $limit<.1 || $limit>$spec['cores']) {
            throw new Fault('CPU 上限请输入 0.1 至 CPU 核数之间的数值');
        }
        $spec['cpu_limit']=(float)$limit;
        if ($spec['kind']==='lxc') { $spec['swap_mib']=Util::integer($input['swap_mib'] ?? null,0,1048576,'Swap（MiB）'); }
        $disk=Disks::size($input['disk_gib'] ?? null);
        if ($disk<$spec['disk_gib']) { throw new Fault('系统盘只支持扩容'); }
        $spec['disk_gib']=$disk;
        foreach ($spec['data_disks'] ?? [] as $i=>$disk) {
            $size=Disks::size($input['disk_size_'.$disk['id']] ?? null);
            if ($size<$disk['size_gib']) { throw new Fault('数据盘 '.$disk['id'].' 只支持扩容'); }
            $spec['data_disks'][$i]['size_gib']=$size;
        }
        $network=[];
        foreach (['bridge','vlan','mtu','dns','prefix4','prefix6','gateway4','gateway6'] as $key) {
            $value=$input[$key] ?? '';
            if (!is_string($value)) { throw new Fault('网络配置格式无效'); }
            $value=trim($value);
            if ($value==='') { continue; }
            $network[$key]=match ($key) {
                'vlan'=>Util::integer($value,0,4094,'VLAN'),
                'mtu'=>Util::integer($value,576,9000,'MTU'),
                'prefix4'=>Util::integer($value,1,32,'IPv4 前缀长度'),
                'prefix6'=>Util::integer($value,1,128,'IPv6 前缀长度'),
                'gateway4','gateway6'=>$value==='-' ? '' : Ipam::address($value,(int)substr($key,-1)),
                'dns'=>self::dns($value),
                'bridge'=>preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D',$value) ? $value : throw new Fault('网桥名称无效'),
            };
        }
        $spec['network']=$network;
        return $spec;
    }

    private static function dns(string $value): string
    {
        if ($value==='-') { return ''; }
        $ips=preg_split('/[\s,，]+/',$value,-1,PREG_SPLIT_NO_EMPTY);
        foreach ($ips as $ip) { if (!filter_var($ip,FILTER_VALIDATE_IP)) { throw new Fault('DNS 请填写 IP 地址'); } }
        return implode(' ',array_unique($ips));
    }

    public static function addresses(mixed $input): array
    {
        if (!is_string($input) || strlen($input)>2048) { throw new Fault('IP 地址格式无效'); }
        $addresses=[];
        $counts=[4=>0,6=>0];
        foreach (preg_split('/[\s,，]+/',trim($input),-1,PREG_SPLIT_NO_EMPTY) as $value) {
            $family=str_contains($value,':') ? 6 : 4;
            $address=Ipam::address($value,$family);
            if (isset($addresses[$address])) { throw new Fault('IP 地址重复：'.$address); }
            if (++$counts[$family]>16) { throw new Fault('每种地址最多填写 16 个'); }
            $addresses[$address]=true;
        }
        $result=array_keys($addresses);
        sort($result,SORT_STRING);
        return $result;
    }
}
