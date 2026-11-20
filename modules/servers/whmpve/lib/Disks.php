<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Disks
{
    public static function size(mixed $value): int|float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || $value<1/1024 || $value>65536) {
            throw new Fault('磁盘容量：请输入 1 MiB–65536 GiB 之间的数值');
        }
        $mib=(int)ceil((float)$value*1024);
        return $mib%1024===0 ? intdiv($mib,1024) : $mib/1024;
    }

    public static function storage(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D',$value)) { throw new Fault('请填写有效的 PVE 存储名称'); }
        return $value;
    }

    public static function mount(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>160 || !preg_match('#^/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)(?:/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$#D',$value)) {
            throw new Fault('挂载点请填写绝对路径，例如 /data');
        }
        foreach (['/bin','/sbin','/lib','/lib64','/etc','/usr','/boot','/dev','/proc','/sys','/run','/root','/var/lib/whmpve'] as $reserved) {
            if ($value===$reserved || str_starts_with($value,$reserved.'/') || str_starts_with($reserved,$value.'/')) { throw new Fault('此路径不能用作数据盘挂载点'); }
        }
        if ($value==='/var') { throw new Fault('此路径不能用作数据盘挂载点'); }
        return $value;
    }

    public static function spec(array $input,int $server): array
    {
        $settings=Util::unpack(ServerConfig::get($server)['settings']);
        $system=self::storage($input['system_storage'] ?? $settings['storage']);
        $rows=$input['data_disks'] ?? [];
        if (!is_array($rows) || count($rows)>16) { throw new Fault('最多添加 16 块数据盘'); }
        $disks=[];
        foreach ($rows as $index=>$row) {
            if (!is_array($row)) { throw new Fault('数据盘配置无效'); }
            $id=Util::integer($row['id'] ?? $index+1,1,16,'数据盘编号');
            if (isset($disks[$id])) { throw new Fault('数据盘编号重复'); }
            $disks[$id]=['id'=>$id,'storage'=>self::storage($row['storage'] ?? ''),
                'size_gib'=>self::size($row['size_gib'] ?? 0),
                'mountpoint'=>self::mount($row['mountpoint'] ?? ''),'allow_custom_mount'=>!empty($row['allow_custom_mount'])];
        }
        $disks=array_values($disks); self::validateMounts($disks);
        return ['system_storage'=>$system,'data_disks'=>$disks];
    }

    public static function validateMounts(array $disks): void
    {
        $seen=[];
        foreach ($disks as $disk) {
            $mount=self::mount($disk['mountpoint']);
            foreach ($seen as $previous) {
                if ($mount===$previous || str_starts_with($mount,$previous.'/') || str_starts_with($previous,$mount.'/')) { throw new Fault('数据盘挂载点不能重复或互相嵌套'); }
            }
            $seen[]=$mount;
        }
    }

    public static function custom(array $spec,array $fields): array
    {
        foreach ($spec['data_disks'] as &$disk) {
            $key='whmpve_mount_'.$disk['id'];
            foreach ($fields as $name=>$value) {
                if (explode('|',(string)$name)[0]===$key && !is_scalar($value) && $value!==null) { throw new Fault('挂载点格式无效'); }
                if (explode('|',(string)$name)[0]===$key && $disk['allow_custom_mount'] && trim((string)$value)!=='') {
                    $disk['mountpoint']=self::mount(trim((string)$value));
                }
            }
        }
        unset($disk); self::validateMounts($spec['data_disks']);
        return $spec;
    }

    public static function syncProduct(int $product): void
    {
        $p=DB::table('tblproducts')->where('id',$product)->where('servertype','whmpve')->first();
        if (!$p) { return; }
        $plan=Util::scoped('plans',(int)$p->configoption2,(int)$p->configoption1);
        $disks=Util::unpack($plan['spec'])['data_disks'] ?? [];
        $fields=DB::table('tblcustomfields')->where('type','product')->where('relid',$product)->get();
        $active=[];
        foreach ($disks as $disk) {
            if (empty($disk['allow_custom_mount'])) { continue; }
            $key='whmpve_mount_'.$disk['id']; $active[]=$key;
            $old=$fields->first(fn($f)=>explode('|',$f->fieldname)[0]===$key);
            $data=['type'=>'product','relid'=>$product,'fieldname'=>$key.'|数据盘 '.$disk['id'].' 挂载点','fieldtype'=>'text',
                'description'=>'留空使用 '.$disk['mountpoint'],'fieldoptions'=>'','regexpr'=>'','adminonly'=>'','required'=>'',
                'showorder'=>'on','showinvoice'=>'','sortorder'=>100+(int)$disk['id']];
            if ($old) { DB::table('tblcustomfields')->where('id',$old->id)->update($data); }
            else { DB::table('tblcustomfields')->insert($data); }
        }
        foreach ($fields as $field) {
            $key=explode('|',$field->fieldname)[0];
            if (preg_match('/^whmpve_mount_\d+$/D',$key) && !in_array($key,$active,true)) {
                DB::table('tblcustomfields')->where('id',$field->id)->update(['showorder'=>'','adminonly'=>'on']);
            }
        }
    }

    public static function slots(array $config,string $kind): array
    {
        return array_filter($config,static function($value,$key) use($kind) {
            if ($kind==='lxc') { return $key==='rootfs' || preg_match('/^mp\d+$/D',(string)$key); }
            return preg_match('/^(scsi|virtio|sata|ide)\d+$/D',(string)$key) && !str_contains($value,'media=cdrom') && !str_contains($value,'cloudinit');
        },ARRAY_FILTER_USE_BOTH);
    }

    public static function cart(array $item): void
    {
        $product=(int)($item['pid'] ?? 0);
        $p=DB::table('tblproducts')->where('id',$product)->where('servertype','whmpve')->first();
        if (!$p) { return; }
        $plan=Util::scoped('plans',(int)$p->configoption2,(int)$p->configoption1);
        $fields=[];
        foreach (DB::table('tblcustomfields')->where('type','product')->where('relid',$product)->get(['id','fieldname']) as $field) {
            $fields[explode('|',$field->fieldname)[0]]=$item['customfields'][$field->id] ?? '';
        }
        self::custom(Util::unpack($plan['spec'])+['data_disks'=>[]],$fields);
    }

    public static function volume(string $value): string
    {
        $volume=explode(',',$value)[0];
        if (str_starts_with($volume,'volume=')) { $volume=substr($volume,7); }
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9_.-]*:[a-zA-Z0-9_./-]+$#D',$volume) || str_contains($volume,'..')) { throw new Fault('磁盘不是可管理的 PVE 存储卷'); }
        return $volume;
    }

    public static function serial(array $instance,int $disk): string { return 'wmp-'.substr($instance['uuid'],0,16).'-'.$disk; }

    public static function ownedVolumes(array $instance,array $config,int $vmid,bool $all=true): array
    {
        foreach ($config as $key=>$value) {
            if (preg_match('/^(unused\d+|dev\d+|lxc\.mount\.entry)$/D',(string)$key)) { throw new IdentityMismatch('存在未登记的磁盘或设备挂载，请由管理员处理',409); }
        }
        $inventory=Util::unpack($instance['disks'] ?? null); $slots=self::slots($config,$instance['kind']);
        if (!$inventory) {
            $system=$instance['kind']==='lxc' ? 'rootfs' : Pve::systemDisk($config)['slot'];
            if (count($slots)!==1 || !isset($slots[$system])) { throw new Fault('存在未登记的数据盘或挂载，请由管理员处理'); }
            return [];
        }
        $expected=[];
        foreach ($inventory as $disk) {
            if ((int)$disk['vmid']!==$vmid) { continue; }
            $slot=$disk['slot'];
            if (!isset($slots[$slot]) || self::volume($slots[$slot])!==$disk['volume']) { throw new IdentityMismatch('磁盘身份不匹配，已停止操作',409); }
            if ($instance['kind']==='lxc' && $disk['id'] && !str_contains($slots[$slot],',mp='.$disk['mountpoint'].',') && !str_ends_with($slots[$slot],',mp='.$disk['mountpoint'])) { throw new IdentityMismatch('数据盘挂载配置已改变',409); }
            $expected[$slot]=$disk;
        }
        if ($all && count($expected)!==count($slots)) { throw new IdentityMismatch('存在未登记的数据盘或挂载，请由管理员处理',409); }
        return $expected;
    }
}
