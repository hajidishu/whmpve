<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Pve
{
    public readonly int $serverId;
    public readonly array $settings;
    private array $server;
    private string $secret;
    private string $base;

    public function __construct(int $serverId,private readonly ?float $deadline=null,private readonly bool $foreground=false)
    {
        $this->serverId=$serverId;
        $this->settings=Util::unpack(ServerConfig::get($serverId)['settings']);
        $s=DB::table('tblservers')->where('id',$serverId)->first();
        if (!$s || $s->type!=='whmpve') { throw new Fault('PVE 连接不存在'); }
        $this->server=(array)$s;
        $host=trim((string)($s->hostname ?: $s->ipaddress));
        if (!preg_match('/^[a-zA-Z0-9.:-]+$/',$host)) { throw new Fault('PVE 地址无效'); }
        $port=Util::integer($s->port ?: 8006,1,65535,'PVE 端口');
        if (empty($s->secure)) { throw new Fault('PVE 连接必须启用 SSL'); }
        if (filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) { $host='['.$host.']'; }
        $this->base='https://'.$host.':'.$port;
        $this->secret=decrypt((string)$s->password);
    }
    public function authority(): string { return $this->base; }
    public function request(string $method,string $path,array $data=[],?array $ticket=null,int $timeout=45): mixed
    {
        $remaining=$this->deadline===null ? null : (int)(($this->deadline-microtime(true))*1000);
        if ($remaining!==null && $remaining<=($this->foreground && $method!=='GET' ? 1000 : 100)) {
            throw new Pending('等待下一轮任务调度');
        }
        $timeoutMs=$timeout*1000;
        if ($this->foreground) { $timeoutMs=min($timeoutMs,$remaining ?? 3000); }
        if (!preg_match('#^/[a-zA-Z0-9_./{}%-]+$#',$path)) { throw new Fault('非法 API 路径'); }
        $url=$this->base.'/api2/json'.$path;
        if (in_array($method,['GET','DELETE'],true) && $data) { $url.='?'.http_build_query($data); }
        $headers=['Accept: application/json'];
        $json=(bool)array_filter($data,'is_array');
        if ($json) { $headers[]='Content-Type: application/json'; }
        if ($ticket) {
            $headers[]='Cookie: PVEAuthCookie='.rawurlencode($ticket['ticket']);
            if ($method!=='GET') { $headers[]='CSRFPreventionToken: '.$ticket['CSRFPreventionToken']; }
        } elseif ($path!=='/access/ticket') {
            $headers[]='Authorization: PVEAPIToken='.$this->server['username'].'='.$this->secret;
        }
        $reason=''; $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT_MS=>min(8000,$timeoutMs),CURLOPT_TIMEOUT_MS=>$timeoutMs,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);
        curl_setopt($ch,CURLOPT_HEADERFUNCTION,function($ch,$line) use (&$reason) { if (preg_match('#^HTTP/\S+ \d+ (.*)#',trim($line),$m)) { $reason=$m[1]; } return strlen($line); });
        if ($this->settings['ca_file']) { curl_setopt($ch,CURLOPT_CAINFO,$this->settings['ca_file']); }
        if (!in_array($method,['GET','DELETE'],true)) { curl_setopt($ch,CURLOPT_POSTFIELDS,$json ? Util::json($data) : http_build_query($data)); }
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $errno=curl_errno($ch); $error=curl_error($ch); curl_close($ch);
        if ($raw===false && $this->foreground && $method==='GET' && $errno===CURLE_OPERATION_TIMEDOUT) {
            throw new Pending('等待后台查询 PVE');
        }
        if ($raw===false) { throw new UnknownPveRequest('PVE 连接失败：'.$error,502); }
        if (strlen($raw)>8*1024*1024) { throw new UnknownPveRequest('PVE 返回数据过大',502); }
        $reply=json_decode($raw,true);
        if ($code<200 || $code>=300 || !is_array($reply) || !array_key_exists('data',$reply)) {
            $detail=is_array($reply['errors'] ?? null) ? Util::json($reply['errors']) : (string)($reply['message'] ?? $reply['errors'] ?? $reason ?: '请检查节点状态和 API 权限');
            foreach ([$this->secret,(string)($data['password'] ?? ''),(string)($data['cipassword'] ?? '')] as $s) {
                if ($s!=='') { $detail=str_replace([$s,substr(Util::json($s),1,-1)],'[REDACTED]',$detail); }
            }
            $message='PVE HTTP '.$code.' ('.$method.' '.$path.')：'.mb_substr($detail,0,700);
            if (in_array($code,[502,503,504],true) || ($code>=500 && !is_array($reply)) ||
                ($code>=200 && $code<300)) { throw new UnknownPveRequest($message,502); }
            $candidate=match (true) {
                $method==='GET' && $path==='/cluster/nextid' => (int)($data['vmid'] ?? 0),
                $method==='POST' && preg_match('#^/nodes/[^/]+/(lxc|qemu)$#D',$path) => (int)($data['vmid'] ?? 0),
                $method==='POST' && str_ends_with($path,'/clone') => (int)($data['newid'] ?? 0),
                default=>0,
            };
            if (in_array($code,[400,409,500],true) && $candidate && self::vmidAlreadyUsed($detail,$candidate)) {
                throw new VmidConflict($message,409);
            }
            throw new PveHttpFault($message,$code,trim($detail));
        }
        return $reply['data'];
    }

    public function permissions(string $path): array
    {
        $result=$this->request('GET','/access/permissions',['path'=>$path]);
        if (!is_array($result) || !is_array($result[$path] ?? [])) { throw new Fault('PVE 权限查询结果无效',502); }
        return $result[$path] ?? [];
    }

    public static function vmidAlreadyUsed(string $message,int $vmid): bool
    {
        return (bool)preg_match('/\b(?:VM|CT|VMID)\s+'.$vmid.'\s+(?:already exists|is already in use)\b/i',$message);
    }

    public function findWorker(string $type,string $id,int $since,?string $node=null): ?string
    {
        $path='/nodes/'.rawurlencode($node ?? $this->settings['node']).'/tasks';
        $filters=['source'=>'all','typefilter'=>$type,'since'=>max(0,$since-2),'limit'=>100];
        if (ctype_digit($id)) { $filters['vmid']=(int)$id; }
        $rows=$this->request('GET',$path,$filters);
        $matches=[];
        foreach ($rows as $task) {
            $upid=$task['upid'] ?? '';
            if ((string)($task['id'] ?? '')!==$id || !str_ends_with($upid,':'.$this->server['username'].':')) { continue; }
            $matches[$upid]=true;
        }
        if (count($matches)>1) { throw new Fault('发现多个可能匹配的 PVE 任务，请先核对',409); }
        return array_key_first($matches);
    }

    public function loadAverage(array $owned): ?array
    {
        $agent=(string)($owned['config']['agent'] ?? '');
        if (!preg_match('/(?:^|,)enabled=1(?:,|$)|^1(?:,|$)/',$agent)) { return null; }
        try {
            $file=$this->request('GET',$owned['path'].'/agent/file-read',['file'=>'/proc/loadavg','count'=>128],timeout:5);
        } catch (Fault) {
            return null;
        }
        $content=$file['content'] ?? null;
        if (!is_string($content) || strlen($content)>128 ||
            !preg_match('/^\s*(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)(?=\s|$)/',$content,$values)) {
            return null;
        }
        return [(float)$values[1],(float)$values[2],(float)$values[3]];
    }
    public function resources(): array { return $this->request('GET','/cluster/resources',['type'=>'vm']); }
    public function locate(int $vmid): ?array
    {
        $resource=$this->resource($vmid);
        if ($resource && $resource['node']!==$this->settings['node']) { throw new Fault('实例位于另一节点，请先核对服务器配置'); }
        return $resource;
    }
    public function resource(int $vmid): ?array
    {
        foreach ($this->resources() as $resource) {
            if ((int)($resource['vmid'] ?? 0)!==$vmid) { continue; }
            return $resource;
        }
        return null;
    }
    public function path(string $node,string $kind,int $vmid): string
    {
        if ($node!==$this->settings['node'] || !in_array($kind,['lxc','qemu'],true)) { throw new Fault('实例不属于此服务器节点'); }
        return '/nodes/'.rawurlencode($node).'/'.$kind.'/'.$vmid;
    }
    public function owned(array $instance,?int $vmid=null): ?array
    {
        $vmid=$vmid ?? (int)$instance['vmid'];
        if ($vmid<$this->settings['vmid_min'] || $vmid>$this->settings['vmid_max']) { throw new Fault('VMID 超出服务器范围'); }
        $location=$this->locate($vmid);
        if (!$location) { return null; }
        if (($location['type'] ?? '')!==$instance['kind']) { throw new IdentityMismatch('实例类型不匹配，已阻止操作',409); }
        $path=$this->path($location['node'],$instance['kind'],$vmid);
        $config=$this->request('GET',$path.'/config');
        if (!str_contains((string)($config['description'] ?? ''),self::marker($instance))) { throw new IdentityMismatch('实例身份标识不匹配，已阻止操作',409); }
        if (!empty($location['pool']) && $location['pool']!==$this->settings['pve_pool']) { throw new Fault('实例已离开受管资源池'); }
        return ['path'=>$path,'node'=>$location['node'],'config'=>$config,'status'=>$location['status'] ?? 'unknown','vmid'=>$vmid];
    }
    public static function marker(array $instance): string { return 'WHMPVE:'.$instance['service_id'].':'.$instance['uuid']; }
    public function setOnboot(array $owned,bool $enabled): void
    {
        if (!empty($owned['config']['onboot'])===$enabled) { return; }
        if (!empty($owned['config']['lock'])) { throw new Pending('等待 PVE 实例释放配置锁'); }
        try {
            $this->request('PUT',$owned['path'].'/config',['onboot'=>(int)$enabled,'digest'=>$owned['config']['digest']]);
        } catch (PveHttpFault $e) {
            if (str_starts_with($e->detail,'detected modified configuration') || preg_match('/^(?:VM|CT) is locked \(/i',$e->detail)) {
                throw new Pending('等待重新读取 PVE 实例配置');
            }
            throw $e;
        }
    }
    public static function sizeGib(string $volume): float
    {
        if (!preg_match('/\bsize=(\d+(?:\.\d+)?)([KMGTPE])\b/',$volume,$m)) { throw new Fault('无法读取系统盘容量，请检查模板配置'); }
        return (float)$m[1]*(['K'=>1/1048576,'M'=>1/1024,'G'=>1,'T'=>1024,'P'=>1048576,'E'=>1073741824][$m[2]]);
    }
    public static function systemDisk(array $config): array
    {
        $candidates=[];
        if (preg_match('/order=([^,]+)/',(string)($config['boot'] ?? ''),$m)) { $candidates=explode(';',$m[1]); }
        if (!empty($config['bootdisk'])) { $candidates[]=$config['bootdisk']; }
        $candidates=array_unique(array_merge($candidates,['scsi0','virtio0','sata0']));
        foreach ($candidates as $slot) {
            if (preg_match('/^(scsi|virtio|sata|ide)\d+$/',$slot) && isset($config[$slot]) && !str_contains($config[$slot],'media=cdrom') && !str_contains($config[$slot],'cloudinit')) {
                return ['slot'=>$slot,'gib'=>self::sizeGib($config[$slot])];
            }
        }
        throw new Fault('KVM 模板缺少支持的系统盘');
    }
    public function chooseNode(array $spec,array $image,bool $replacement=false): string
    {
        if (!ServerConfig::get($this->serverId)['active']) { throw new Fault('服务器已停用'); }
        $limit=(int)($this->settings['max_instances'] ?? ServerConfig::DEFAULT_LIMITS['max_instances']);
        if (!$replacement && $limit>0 && DB::table('mod_whmpve_instances')->where('server_id',$this->serverId)
            ->whereNotNull('vmid')->where('lifecycle','!=','terminated')->count()>=$limit) { throw new Fault('服务器实例数量已达到上限'); }
        $node=$this->settings['node'];
        $n=$this->request('GET','/nodes/'.rawurlencode($node).'/status');
        $free=(int)($n['memory']['available'] ?? $n['memory']['free'] ?? 0);
        if ($free<($spec['memory_mib']+$this->settings['reserve_memory_mib'])*1048576) { throw new Fault('服务器可用内存不足'); }
        $needed=[($spec['system_storage'] ?? $this->settings['storage'])=>$spec['disk_gib']];
        if (!$replacement) { foreach ($spec['data_disks'] ?? [] as $disk) { $needed[$disk['storage']]=($needed[$disk['storage']] ?? 0)+$disk['size_gib']; } }
        $available=$this->request('GET','/nodes/'.rawurlencode($node).'/storage',['content'=>$spec['kind']==='lxc' ? 'rootdir' : 'images','enabled'=>1]);
        foreach ($needed as $name=>$size) {
            $entry=array_values(array_filter($available,fn($s)=>$s['storage']===$name))[0] ?? [];
            if (empty($entry['active']) || ($entry['avail'] ?? 0)<$size*1073741824) { throw new Fault('存储 '.$name.' 不可用、类型不兼容或空间不足'); }
        }
        if ($image['kind']==='lxc') {
            $images=$this->request('GET','/nodes/'.rawurlencode($node).'/storage/'.rawurlencode(explode(':',$image['source'])[0]).'/content',['content'=>'vztmpl']);
            if (!array_filter($images,fn($x)=>$x['volid']===$image['source'])) { throw new Fault('该服务器上没有所选模板文件'); }
        }
        return $node;
    }
    public function allocateId(array $excluded=[]): int
    {
        $lock='whmpve_vmid_'.$this->serverId;
        $r=DB::select('SELECT GET_LOCK(?, 5) as acquired',[$lock]);
        if (empty($r[0]->acquired)) { throw new Fault('VMID 分配繁忙，请稍后重试'); }
        try {
            $used=array_map(fn($r)=>(int)$r['vmid'],$this->resources());
            $used=array_merge($used,DB::table('mod_whmpve_instances')->where('server_id',$this->serverId)->whereNotNull('vmid')->pluck('vmid')->all());
            $liveTasks=DB::table('mod_whmpve_tasks')->where('server_id',$this->serverId)->whereIn('status',['queued','running','failed'])->get(['context']);
            foreach ($liveTasks as $task) { $ctx=Util::unpack($task->context); if (isset($ctx['new_vmid'])) { $used[]=(int)$ctx['new_vmid']; } }
            $used=array_fill_keys(array_map('intval',array_merge($used,$excluded)),true);
            for ($id=$this->settings['vmid_min'];$id<=$this->settings['vmid_max'];$id++) { if (!isset($used[$id])) { return $id; } }
            throw new Fault('VMID 范围已用尽');
        } finally { DB::select('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    public function ips(int $serviceId): array
    {
        return DB::table('mod_whmpve_ips as i')->join('mod_whmpve_ip_pools as p','p.id','=','i.pool_id')
            ->where('i.server_id',$this->serverId)->where('p.server_id',$this->serverId)
            ->where('i.service_id',$serviceId)->whereIn('i.state',['reserved','assigned'])
            ->orderBy('i.id')->get(['i.*','p.bridge','p.vlan','p.dns','p.mtu'])->map(fn($row)=>(array)$row)->all();
    }
    public function reserveIp(array $instance,array $spec): void
    {
        Ipam::reserve($instance,$spec);
    }
    public function network(array $instance,array $spec,?array $ips=null): array
    {
        $slots=[]; $dns=[]; $gateways=[];
        $overrides=$spec['network'] ?? [];
        foreach ($ips ?? $this->ips((int)$instance['service_id']) as $ip) {
            $family=(int)$ip['family']; $match=null;
            foreach (['bridge','vlan','dns','mtu','prefix','gateway'] as $key) {
                $override=in_array($key,['prefix','gateway'],true) ? $key.$family : $key;
                $ip[$key]=$overrides[$override] ?? $ip[$key];
            }
            foreach ($slots as $index=>$slot) {
                if (!isset($slot['ips'][$family]) && $slot['bridge']===$ip['bridge'] && $slot['vlan']===$ip['vlan'] && $slot['mtu']===$ip['mtu']) { $match=$index; break; }
            }
            if ($match===null) { $slots[]=['bridge'=>$ip['bridge'],'vlan'=>$ip['vlan'],'mtu'=>$ip['mtu'],'ips'=>[]]; $match=count($slots)-1; }
            $slots[$match]['ips'][$family]=$ip;
            $dns=array_merge($dns,preg_split('/\s+/',trim($ip['dns']),-1,PREG_SPLIT_NO_EMPTY));
        }
        $data=[];
        foreach ($slots as $index=>$slot) {
            $net=$instance['kind']==='lxc' ? 'name=eth'.$index.',type=veth' : 'virtio';
            if ($slot['bridge']!=='') { $net.=',bridge='.$slot['bridge']; }
            if ($slot['vlan']) { $net.=',tag='.$slot['vlan']; }
            if ($slot['bridge']!=='') { $net.=',mtu='.$slot['mtu']; }
            $config=[];
            foreach ($slot['ips'] as $family=>$ip) {
                $key=$family===4 ? 'ip' : 'ip6'; $gw=$family===4 ? 'gw' : 'gw6';
                $config[]=$key.'='.$ip['address'].'/'.$ip['prefix'];
                if ($ip['gateway'] && empty($gateways[$family])) { $config[]=$gw.'='.$ip['gateway']; $gateways[$family]=true; }
            }
            if ($instance['kind']==='lxc') { $net.=','.implode(',',$config); }
            else { $data['ipconfig'.$index]=implode(',',$config); }
            $data['net'.$index]=$net;
        }
        if ($dns) { $data['nameserver']=implode(' ',array_unique($dns)); }
        return array_merge($data,self::rates($data,(float)$spec['bandwidth_mbps']));
    }

    public static function updateNetwork(array $config,array $network,string $kind): array
    {
        foreach ($network as $key=>&$value) {
            if (!preg_match('/^net\d+$/D',$key) || !isset($config[$key])) { continue; }
            $old=self::networkProperties($config[$key]);
            if ($kind==='qemu') {
                $model=array_key_first($old);
                $value=explode(',',$config[$key],2)[0].substr($value,strlen('virtio'));
                unset($old[$model]);
            }
            foreach (['bridge','tag','mtu','rate','ip','ip6','gw','gw6','name','type'] as $field) { unset($old[$field]); }
            foreach ($old as $field=>$valueBefore) { $value.=','.$field.($valueBefore==='' ? '' : '='.$valueBefore); }
        }
        unset($value);
        $remove=array_filter(array_keys($config),fn($key)=>preg_match('/^(net|ipconfig)\d+$/D',$key) && !isset($network[$key]));
        if (isset($config['nameserver']) && !isset($network['nameserver'])) { $remove[]='nameserver'; }
        if ($remove) { $network['delete']=implode(',',$remove); }
        return $network;
    }

    private static function networkProperties(string $value): array
    {
        $props=[];
        foreach (explode(',',$value) as $part) {
            [$key,$setting]=array_pad(explode('=',$part,2),2,'');
            $props[$key]=$setting;
        }
        return $props;
    }

    public static function networkApplied(array $config,array $network): bool
    {
        $managed=fn($key)=>$key==='nameserver' || preg_match('/^(net|ipconfig)\d+$/D',$key);
        if (array_diff_key(array_filter($config,$managed,ARRAY_FILTER_USE_KEY),$network)) { return false; }
        foreach ($network as $key=>$value) {
            if (!isset($config[$key])) { return false; }
            if ($key==='nameserver') {
                if (preg_split('/\s+/',trim($config[$key]))!==preg_split('/\s+/',trim($value))) { return false; }
                continue;
            }
            $expected=self::networkProperties($value); $actual=self::networkProperties($config[$key]);
            foreach (['bridge','mtu','tag','ip','ip6','gw','gw6'] as $field) {
                if (($expected[$field] ?? null)!==($actual[$field] ?? null)) { return false; }
            }
        }
        return true;
    }

    public static function rates(array $config,float $mbps): array
    {
        $nets=array_filter($config,fn($key)=>preg_match('/^net\d+$/',(string)$key),ARRAY_FILTER_USE_KEY);
        $result=[];
        foreach ($nets as $key=>$value) {
            $parts=array_values(array_filter(explode(',',$value),fn($p)=>!str_starts_with($p,'rate=')));
            if ($mbps>0) { $parts[]='rate='.rtrim(rtrim(sprintf('%.6f',$mbps*1000000/8/1048576/count($nets)),'0'),'.'); }
            $result[$key]=implode(',',$parts);
        }
        return $result;
    }
    public function createData(array $instance,array $spec,array $image,int $vmid,string $password): array
    {
        if ($image['kind']!=='lxc') { throw new Fault('错误的创建方式'); }
        $data=array_merge(['vmid'=>$vmid,'ostemplate'=>$image['source'],
            'hostname'=>$instance['hostname'],'memory'=>$spec['memory_mib'],'swap'=>$spec['swap_mib'] ?? 0,'cores'=>$spec['cores'],'cpulimit'=>$spec['cpu_limit'],
            'unprivileged'=>1,'rootfs'=>($spec['system_storage'] ?? $this->settings['storage']).':'.Util::json($spec['disk_gib']),'onboot'=>0,
            'pool'=>$this->settings['pve_pool'],'description'=>self::marker($instance),'password'=>$password,'start'=>0],$this->network($instance,$spec));
        if (isset($spec['nesting'])) { $data['features']='nesting='.(int)$spec['nesting']; }
        foreach ($spec['data_disks'] ?? [] as $disk) { $data['mp'.($disk['id']-1)]=$disk['storage'].':'.Util::json($disk['size_gib']).',mp='.$disk['mountpoint'].',backup=1'; }
        foreach ($this->request('GET','/cluster/resources',['type'=>'node']) as $node) {
            if (($node['node'] ?? '')!==$this->settings['node']) { continue; }
            $arch=['aarch64'=>'arm64','x86_64'=>'amd64','arm64'=>'arm64','amd64'=>'amd64'][$node['host-arch'] ?? ''] ?? null;
            if ($arch) { $data['arch']=$arch; }
        }
        return $data;
    }
    public function inspect(): array
    {
        $version=$this->request('GET','/version');
        $s=$this->request('GET','/nodes/'.rawurlencode($this->settings['node']).'/status');
        $storage='/storage/'.($this->settings['backup_storage'] ?? 'local');
        $missing=[];
        if (empty($this->permissions('/vms')['VM.Backup'])) { $missing[]='/vms 的 VM.Backup（传播）'; }
        if (!array_key_exists('Datastore.AllocateSpace',$this->permissions($storage))) { $missing[]=$storage.' 的 Datastore.AllocateSpace'; }
        return ['version'=>$version['version'],'node'=>$this->settings['node'],'memory_total'=>$s['memory']['total'],'memory_used'=>$s['memory']['used'],
            'backup_missing'=>$missing];
    }
    public function consoleTicket(array $instance,string $mode): array
    {
        $owned=$this->owned($instance);
        if (!$owned || ($this->request('GET',$owned['path'].'/status/current')['status'] ?? '')!=='running') { throw new Fault('实例运行后才能打开控制台'); }
        if (!$this->settings['console_user'] || !$this->settings['console_password'] || !$this->settings['console_url']) { throw new Fault('管理员尚未配置控制台连接'); }
        $endpoint = $mode==='novnc' ? '/vncproxy' : '/termproxy';
        $params = $mode==='novnc' ? ['websocket'=>1] : [];
        if ($mode==='xterm' && $instance['kind']==='qemu') {
            foreach (['serial0','serial1','serial2','serial3'] as $serial) {
                if (($owned['config'][$serial] ?? '')==='socket') { $params['serial']=$serial; break; }
            }
            if (!$params) { throw new Fault('请联系管理员为虚拟机添加串口，或使用 noVNC'); }
        }
        $ticket=$this->request('POST','/access/ticket',['username'=>$this->settings['console_user'],'password'=>decrypt($this->settings['console_password'])]);
        $proxy=$this->request('POST',$owned['path'].$endpoint,$params,$ticket);
        return ['auth'=>$ticket,'proxy'=>$proxy,'path'=>$owned['path'].'/vncwebsocket','authority'=>$this->base,'ca_file'=>$this->settings['ca_file']];
    }
}
