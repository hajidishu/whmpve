<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Console
{
    public const LEASE_SECONDS = 30;
    public const MAX_SECONDS = 3600;

    private static function storages(bool $create=false): \Generator
    {
        $web = realpath(dirname(__DIR__,4));
        $document = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $configured = trim((string)Util::setting('storage_path',''));
        $site = hash('sha256',$web ?: dirname(__DIR__,4));
        $name = 'whmpve-console-'.$site;
        $locations = $configured==='' ? [['/dev/shm/'.$name,'s'.$site],['/tmp/'.$name,'t'.$site]] : [[$configured,null]];
        foreach ($locations as [$location,$scope]) {
            if ($scope!==null) {
                if ($create && !@is_dir($location)) { @mkdir($location,0700); }
                clearstatcache(true,$location);
                if (@is_link($location) || (function_exists('posix_geteuid') && @fileowner($location)!==posix_geteuid())) { continue; }
            }
            $path=@realpath($location);
            if (!$path || !@is_dir($path) || !@is_writable($path) || (@fileperms($path)&0077)
                || ($web && ($path===$web || str_starts_with($path,$web.DIRECTORY_SEPARATOR)))
                || ($document && ($path===$document || str_starts_with($path,$document.DIRECTORY_SEPARATOR)))) { continue; }
            yield [$path,$scope];
        }
    }

    private static function storage(bool $create=false,?string $token=null): array
    {
        foreach (self::storages($create) as [$path,$scope]) {
            if ($token===null || @is_file(self::path($path,$token))) { return [$path,$scope]; }
        }
        if ($token!==null) { throw new Fault('控制台连接已失效',403); }
        throw new Fault('无法创建或打开私有控制台目录');
    }

    private static function file(string $path,string $mode='r+b')
    {
        if (is_link($path)) { throw new Fault('控制台连接已失效',403); }
        $old = umask(0077);
        try { $file = @fopen($path,$mode); } finally { umask($old); }
        if (!$file) { throw new Fault('控制台连接已失效',403); }
        $stat = fstat($file); clearstatcache(true,$path); $entry = @lstat($path);
        if (!$entry || ($stat['mode']&0170000)!==0100000 || ($stat['mode']&0077)
            || $stat['nlink']!==1 || $entry['ino']!==$stat['ino'] || $entry['dev']!==$stat['dev']) {
            fclose($file); throw new Fault('控制台连接已失效',403);
        }
        return $file;
    }

    private static function linked($file,string $path): void
    {
        clearstatcache(true,$path); $entry=@lstat($path); $stat=fstat($file);
        if (!$entry || $entry['ino']!==$stat['ino'] || $entry['dev']!==$stat['dev'] || $stat['nlink']!==1) {
            throw new Fault('控制台连接已失效',403);
        }
    }

    private static function read($file): array
    {
        rewind($file); $raw = stream_get_contents($file,32769);
        if (strlen($raw)>32768) { throw new Fault('控制台连接已失效',403); }
        try { $record = json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Fault('控制台连接已失效',403); }
        if (!is_array($record)) { throw new Fault('控制台连接已失效',403); }
        return $record;
    }

    private static function write($file,array $record): void
    {
        $raw = Util::json($record); rewind($file);
        if (!ftruncate($file,0) || fwrite($file,$raw)!==strlen($raw) || !fflush($file)) { throw new Fault('无法保存控制台授权'); }
    }

    private static function lock($file,int $operation=LOCK_EX): void
    {
        if (!flock($file,$operation)) { throw new Fault('控制台连接暂时不可用',503); }
    }

    private static function path(string $directory,string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) { throw new Fault('控制台连接已失效',403); }
        return $directory.'/'.$token.'.json';
    }

    private static function active(int $service,array $identity): array
    {
        if (!Util::enabled()) { throw new Fault('管理插件已停用',403); }
        $native = Http::own($service,$identity['id']);
        $instance = Jobs::instance($service);
        if ($native['domainstatus']!=='Active' || $instance['lifecycle']!=='active' || !$instance['vmid']
            || DB::table('tblclients')->where('id',$identity['id'])->value('status')==='Closed') {
            throw new Fault('当前服务不能使用控制台',403);
        }
        return $instance;
    }

    private static function rate(string $key,int $limit): void
    {
        $bucket = intdiv(time(),60);
        $key = 'console-rate:'.$key.':'.$bucket;
        DB::table('mod_whmpve_cache')->insertOrIgnore(['cache_key'=>$key,'value'=>'0','expires_at'=>($bucket+2)*60,
            'created_at'=>Util::now(),'updated_at'=>Util::now()]);
        $updated = DB::table('mod_whmpve_cache')->where('cache_key',$key)->whereRaw('CAST(value AS UNSIGNED) < ?',[$limit])
            ->update(['value'=>DB::raw('CAST(value AS UNSIGNED) + 1'),'updated_at'=>Util::now()]);
        if (!$updated) { throw new Fault('控制台申请过于频繁，请稍后重试',429); }
    }

    private static function expired(array $record): bool
    {
        $now = time();
        return ($record['expires'] ?? 0)<=$now || ($record['lease_until'] ?? $record['expires'] ?? 0)<=$now
            || (in_array($record['state'] ?? '',['issuing','issued'],true) && ($record['connect_before'] ?? 0)<=$now);
    }

    private static function records(string $directory): \Generator
    {
        foreach (new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!preg_match('/^[a-f0-9]{64}(?:\.json|\.used-[a-f0-9]{8})$/D',$entry->getFilename())) { continue; }
            try { $file = self::file($entry->getPathname()); } catch (Fault) { continue; }
            try {
                if (!flock($file,LOCK_EX)) { continue; }
                try { $record = self::read($file); }
                catch (Fault) { $record = []; }
                if (self::expired($record)) { @unlink($entry->getPathname()); }
                else { yield $record; }
            } finally { flock($file,LOCK_UN); fclose($file); }
        }
    }

    public static function cleanup(): void
    {
        foreach (self::storages() as [$directory]) {
            try {
                $lock = self::file($directory.'/.whmpve-console.lock','c+b');
                try {
                    if (flock($lock,LOCK_EX|LOCK_NB)) { foreach (self::records($directory) as $_) {} }
                } finally { flock($lock,LOCK_UN); fclose($lock); }
            } catch (Fault) { /* The relay directory is unavailable. */ }
        }
        DB::table('mod_whmpve_cache')->where('cache_key','like','console-rate:%')->where('expires_at','<=',time())->delete();
    }

    public static function open(int $service,array $identity,mixed $mode=null): array
    {
        $instance = self::active($service,$identity);
        $mode ??= $instance['kind']==='qemu' ? 'novnc' : 'xterm';
        if (!in_array($mode,['novnc','xterm'],true)) { throw new Fault('请选择有效的控制台类型'); }
        [$directory,$site] = self::storage(true);
        self::rate('service:'.$service,5);
        self::rate('subject:'.$identity['subject'],20);
        $token = bin2hex(random_bytes(32)); $path = self::path($directory,$token); $now = time();
        $scope=$identity['scope'] ?? 'client';
        $record = ['version'=>2,'service_id'=>$service,'client_id'=>$identity['id'],'subject'=>$identity['subject'],'session'=>$identity['session'],
            'scope'=>$scope,
            'uuid'=>$instance['uuid'],'vmid'=>(int)$instance['vmid'],'server_id'=>(int)$instance['server_id'],'state'=>'issuing',
            'expires'=>$now+self::MAX_SECONDS,'lease_until'=>$now+120,'connect_before'=>$now+120,
            'client_ip'=>$_SERVER['REMOTE_ADDR'] ?? '','origin'=>preg_replace('#^(https?://[^/]+).*#','$1',Util::base())];
        $lock = self::file($directory.'/.whmpve-console.lock','c+b');
        try {
            self::lock($lock); $total=0; $user=0; $guest=0;
            foreach (self::records($directory) as $existing) {
                ++$total;
                if (($existing['subject'] ?? '')===$identity['subject']) { ++$user; }
                if (($existing['service_id'] ?? 0)===$service) { ++$guest; }
            }
            if ($total>=512 || $user>=4 || $guest>=2) { throw new Fault('控制台连接数量已达上限，请关闭不用的连接',429); }
            $file = self::file($path,'x+b');
            try { self::lock($file); self::write($file,$record); } finally { fclose($file); }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        try {
            $ticket = (new Pve((int)$instance['server_id']))->consoleTicket($instance,$mode);
            $fresh=$scope==='admin' ? Http::adminConsoleIdentity($service,(int)$instance['server_id']) : Http::client(true);
            if ($fresh['id']!==$identity['id'] || $fresh['subject']!==$identity['subject'] || $fresh['session']!==$identity['session']) {
                throw new Fault('登录会话已变更，请重新连接',403);
            }
            $current = self::active($service,$fresh);
            if ($current['uuid']!==$record['uuid'] || (int)$current['vmid']!==$record['vmid']) { throw new Fault('实例已变更，请重新连接',403); }
            $file = self::file($path);
            try {
                self::lock($file); self::linked($file,$path); $record = self::read($file);
                if (self::expired($record) || $record['state']!=='issuing') { throw new Fault('控制台申请已超时',403); }
                $record += $ticket;
                $record['state']='issued'; $record['connect_before']=time()+60; $record['lease_until']=time()+self::LEASE_SECONDS;
                self::write($file,$record);
            } finally { fclose($file); }
            $server = ServerConfig::get((int)$instance['server_id']); $settings = Util::unpack($server['settings']);
            $proxy = $ticket['proxy'];
            $location=$site===null ? $token : $site.'/'.$token;
            return ['url'=>$settings['console_url'].'/'.$location,'connection_id'=>$token,'lease_until'=>$record['lease_until'],
                'kind'=>$instance['kind'],'mode'=>$mode,'user'=>$proxy['user'] ?? '',
                'ticket'=>$mode==='novnc' ? ($proxy['password'] ?? $proxy['ticket']) : $proxy['ticket']];
        } catch (\Throwable $e) { @unlink($path); throw $e; }
    }

    public static function renew(int $service,array $identity,string $token,bool $close=false): array
    {
        $instance = self::active($service,$identity); [$directory] = self::storage(token:$token);
        $path = self::path($directory,$token); $file = self::file($path);
        try {
            self::lock($file); self::linked($file,$path); $record = self::read($file);
            if (($record['version'] ?? 0)!==2 || ($record['service_id'] ?? 0)!==$service || ($record['client_id'] ?? 0)!==$identity['id']
                || ($record['scope'] ?? 'client')!==($identity['scope'] ?? 'client')
                || ($record['subject'] ?? '')!==$identity['subject'] || !hash_equals((string)($record['session'] ?? ''),$identity['session'])) {
                throw new Fault('控制台连接不属于当前会话',403);
            }
            if ($close) { @unlink($path); return ['closed'=>true]; }
            if (self::expired($record) || !in_array($record['state'],['issued','connected'],true)
                || $record['uuid']!==$instance['uuid'] || $record['vmid']!==(int)$instance['vmid'] || $record['server_id']!==(int)$instance['server_id']) {
                @unlink($path); throw new Fault('控制台授权已失效，请重新连接',403);
            }
            $record['lease_until']=min(time()+self::LEASE_SECONDS,$record['expires']); self::write($file,$record);
            return ['lease_until'=>$record['lease_until']];
        } finally { flock($file,LOCK_UN); fclose($file); }
    }
}
