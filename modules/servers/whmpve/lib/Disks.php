<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Disks
{
    public static function size(mixed $value): int|float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || $value<1/1024 || $value>65536) {
            throw Fault::localized('message.enter_a_disk_size_from_1_mib');
        }
        $mib=(int)ceil((float)$value*1024);
        return $mib%1024===0 ? intdiv($mib,1024) : $mib/1024;
    }

    public static function storage(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D',$value)) { throw Fault::localized('message.enter_a_valid_pve_storage_name'); }
        return $value;
    }

    public static function mount(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>160 || !preg_match('#^/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)(?:/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$#D',$value)) {
            throw Fault::localized('message.enter_an_absolute_path_for_the_mount');
        }
        foreach (['/bin','/sbin','/lib','/lib64','/etc','/usr','/boot','/dev','/proc','/sys','/run','/root','/var/lib/whmpve'] as $reserved) {
            if ($value===$reserved || str_starts_with($value,$reserved.'/') || str_starts_with($reserved,$value.'/')) { throw Fault::localized('message.this_path_cannot_be_used_as_a'); }
        }
        if ($value==='/var') { throw Fault::localized('message.this_path_cannot_be_used_as_a'); }
        return $value;
    }

    public static function spec(array $input,int $server): array
    {
        $settings=Util::unpack(ServerConfig::get($server)['settings']);
        $system=self::storage($input['system_storage'] ?? $settings['storage']);
        $rows=$input['data_disks'] ?? [];
        if (!is_array($rows) || count($rows)>16) { throw Fault::localized('ui.add_up_to_16_data_disks'); }
        $disks=[];
        foreach ($rows as $index=>$row) {
            if (!is_array($row)) { throw Fault::localized('message.invalid_data_disk_configuration'); }
            $id=Util::integer($row['id'] ?? $index+1,1,16,Lang::text('message.data_disk_id'));
            if (isset($disks[$id])) { throw Fault::localized('message.duplicate_data_disk_id'); }
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
                if ($mount===$previous || str_starts_with($mount,$previous.'/') || str_starts_with($previous,$mount.'/')) { throw Fault::localized('message.data_disk_mount_points_must_be_distinct'); }
            }
            $seen[]=$mount;
        }
    }

    public static function custom(array $spec,array $fields): array
    {
        foreach ($spec['data_disks'] as &$disk) {
            $key='whmpve_mount_'.$disk['id'];
            foreach ($fields as $name=>$value) {
                if (explode('|',(string)$name)[0]===$key && !is_scalar($value) && $value!==null) { throw Fault::localized('message.invalid_mount_point_format'); }
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
        $language=(string)\WHMCS\Config\Setting::getValue('Language');
        $active=[];
        foreach ($disks as $disk) {
            if (empty($disk['allow_custom_mount'])) { continue; }
            $key='whmpve_mount_'.$disk['id']; $active[]=$key;
            $old=$fields->first(fn($f)=>explode('|',$f->fieldname)[0]===$key);
            $data=['type'=>'product','relid'=>$product,'fieldname'=>$key.'|'.Lang::text('message.mount_point_field',['disk'=>$disk['id']],$language),'fieldtype'=>'text',
                'description'=>Lang::text('message.mount_point_default',['path'=>$disk['mountpoint']],$language),'fieldoptions'=>'','regexpr'=>'','adminonly'=>'','required'=>'',
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

    public static function cartFields(array $fields,string $language): array
    {
        $rows=DB::table('tblcustomfields as f')->join('tblproducts as p','p.id','=','f.relid')
            ->join('mod_whmpve_plans as m',function($join) {
                $join->on('m.id','=','p.configoption2')->on('m.server_id','=','p.configoption1');
            })->where('f.type','product')->where('p.servertype','whmpve')
            ->whereIn('f.id',array_column($fields,'id'))->get(['f.id','f.fieldname','m.spec'])->keyBy('id');
        foreach ($fields as &$field) {
            $row=$rows[$field['id'] ?? 0] ?? null;
            if (!$row || !preg_match('/^whmpve_mount_(\d+)$/D',explode('|',$row->fieldname)[0],$match)) { continue; }
            foreach (Util::unpack($row->spec)['data_disks'] ?? [] as $disk) {
                if ((int)$disk['id']!==(int)$match[1] || empty($disk['allow_custom_mount'])) { continue; }
                $field['name']=Util::html(Lang::text('message.mount_point_field',['disk'=>$disk['id']],$language));
                $field['description']=Util::html(Lang::text('message.mount_point_default',['path'=>$disk['mountpoint']],$language));
                break;
            }
        }
        return $fields;
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
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9_.-]*:[a-zA-Z0-9_./-]+$#D',$volume) || str_contains($volume,'..')) { throw Fault::localized('message.the_disk_is_not_a_manageable_pve'); }
        return $volume;
    }

    public static function serial(array $instance,int $disk): string { return 'wmp-'.substr($instance['uuid'],0,16).'-'.$disk; }

    public static function ownedVolumes(array $instance,array $config,int $vmid,bool $all=true): array
    {
        foreach ($config as $key=>$value) {
            if (preg_match('/^(unused\d+|dev\d+|lxc\.mount\.entry)$/D',(string)$key)) { throw IdentityMismatch::localized('message.an_unregistered_disk_or_device_mount_needs',409); }
        }
        $inventory=Util::unpack($instance['disks'] ?? null); $slots=self::slots($config,$instance['kind']);
        if (!$inventory) {
            $system=$instance['kind']==='lxc' ? 'rootfs' : Pve::systemDisk($config)['slot'];
            if (count($slots)!==1 || !isset($slots[$system])) { throw Fault::localized('message.an_unregistered_data_disk_or_mount_needs'); }
            return [];
        }
        $expected=[];
        foreach ($inventory as $disk) {
            if ((int)$disk['vmid']!==$vmid) { continue; }
            $slot=$disk['slot'];
            if (!isset($slots[$slot]) || self::volume($slots[$slot])!==$disk['volume']) { throw IdentityMismatch::localized('message.disk_identity_mismatch_the_operation_was_stopped',409); }
            if ($instance['kind']==='lxc' && $disk['id'] && !str_contains($slots[$slot],',mp='.$disk['mountpoint'].',') && !str_ends_with($slots[$slot],',mp='.$disk['mountpoint'])) { throw IdentityMismatch::localized('message.the_data_disk_mount_configuration_has_changed',409); }
            $expected[$slot]=$disk;
        }
        if ($all && count($expected)!==count($slots)) { throw IdentityMismatch::localized('message.an_unregistered_data_disk_or_mount_needs',409); }
        return $expected;
    }
}
