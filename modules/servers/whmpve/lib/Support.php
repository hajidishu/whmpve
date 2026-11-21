<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

class Fault extends \RuntimeException
{
    public ?array $translation = null;

    public function __construct(string $message, public readonly int $http = 422)
    {
        parent::__construct($message);
    }

    public static function localized(string $key,int $http=422,array $values=[]): static
    {
        $fault=new static(Lang::text($key,$values),$http);
        $fault->translation=['key'=>$key,'values'=>$values];
        return $fault;
    }
}
final class IdentityMismatch extends Fault {}
final class UnknownPveRequest extends Fault {}
final class PveHttpFault extends Fault
{
    public function __construct(string $message,public readonly int $status,public readonly string $detail)
    {
        parent::__construct($message,502);
    }
}
final class VmidConflict extends Fault {}
final class Pending extends \RuntimeException {}

final class Util
{
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function month(?int $time = null): array
    {
        $date = (new \DateTimeImmutable('@'.($time ?? time())))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $start = $date->modify('first day of this month')->setTime(0,0);
        return ['period'=>$start->format('Y-m'),'start'=>$start->getTimestamp(),'end'=>$start->modify('+1 month')->getTimestamp()];
    }
    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function unpack(?string $value): array { return $value ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : []; }
    public static function html(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public static function integer(mixed $value, int $min, int $max, string $name): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) {
            throw new Fault(Lang::text('message.range_required',['field'=>$name,'min'=>$min,'max'=>$max]));
        }
        return (int)$value;
    }

    public static function label(string $value, int $max = 120): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1f]/', $value)) {
            throw Fault::localized('message.enter_a_valid_name');
        }
        return $value;
    }

    public static function row(string $table, int $id): array
    {
        $row = DB::table('mod_whmpve_'.$table)->where('id', $id)->first();
        if (!$row) { throw Fault::localized('message.record_not_found', 404); }
        return (array)$row;
    }

    public static function scoped(string $table, int $id, int $server): array
    {
        $row = self::row($table, $id);
        if ((int)$row['server_id'] !== $server) { throw Fault::localized('message.this_record_does_not_belong_to_the', 404); }
        return $row;
    }

    public static function log(string $message, int $server = 0, int $service = 0, string $level = 'error'): void
    {
        DB::table('mod_whmpve_logs')->insert([
            'server_id'=>$server, 'service_id'=>$service, 'level'=>$level,
            'message'=>mb_substr($message, 0, 2000), 'created_at'=>self::now(),
        ]);
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return DB::table('tbladdonmodules')->where('module', 'whmpve_manager')->where('setting', $key)->value('value') ?? $default;
    }

    public static function enabled(): bool { return self::setting('version') !== null; }
    public static function base(): string { return rtrim((string)DB::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/'); }

    public static function moduleApi(string $command, array $data): array
    {
        $admin = DB::table('tbladmins')->where('roleid', 1)->where('disabled', 0)->value('username');
        if (!$admin) { throw Fault::localized('message.configure_an_administrator_account_authorized_to_run'); }
        $result = localAPI($command, $data, $admin);
        if (($result['result'] ?? '') !== 'success') {
            throw new Fault('WHMCS：'.($result['message'] ?? $command));
        }
        return $result;
    }
}

final class Schema
{
    public const VERSION = '0.5.9';
    public static function installed(): bool { return DB::schema()->hasTable('mod_whmpve_servers'); }

    public static function install(): void
    {
        $schema = DB::schema();
        $tables = [
            'servers'=>function ($t) {
                $t->unsignedInteger('id')->primary(); $t->longText('settings'); $t->boolean('active')->default(true);
            },
            'plans'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->string('name',120); $t->longText('spec'); $t->boolean('active')->default(true);
                $t->text('traffic_reset_prices')->nullable();
            },
            'addons'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->unsignedInteger('plan_id')->index();
                $t->string('name',120); $t->string('kind',16); $t->unsignedInteger('quantity');
                $t->unsignedInteger('pool_id')->nullable()->index(); $t->text('prices');
                $t->unsignedInteger('stock')->nullable(); $t->boolean('active')->default(true);
            },
            'images'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->string('name',120); $t->string('kind',8);
                $t->string('description',500)->default('');
                $t->unsignedInteger('group_id')->nullable()->index();
                $t->string('source',255); $t->unsignedInteger('min_memory'); $t->decimal('min_disk',16,10); $t->boolean('active')->default(true);
            },
            'instances'=>function ($t) {
                $t->unsignedInteger('service_id')->unique(); $t->unsignedInteger('server_id')->index();
                $t->unsignedInteger('plan_id'); $t->unsignedInteger('image_id'); $t->string('uuid',40)->unique(); $t->string('kind',8);
                $t->unsignedInteger('vmid')->nullable(); $t->string('node',64)->nullable(); $t->string('hostname',64);
                $t->string('lifecycle',24)->default('pending'); $t->longText('desired'); $t->longText('applied')->nullable();
                $t->longText('disks')->nullable();
                $t->longText('last_error')->nullable(); $t->unsignedBigInteger('metric_since')->nullable();
                $t->dateTime('auto_backup_since')->nullable();
                $t->string('power_state',16)->default('unknown'); $t->dateTime('power_updated_at')->nullable();
                $t->unique(['server_id','vmid']);
            },
            'tasks'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->unsignedInteger('service_id')->index(); $t->string('action',32);
                $t->string('request_key',64)->unique(); $t->string('actor',80); $t->string('status',16)->default('queued')->index();
                $t->string('stage',120)->default(Lang::text('ui.queued',[],'chinese')); $t->unsignedInteger('stage_number')->default(0); $t->unsignedInteger('stage_total')->default(1);
                $t->longText('payload'); $t->longText('context'); $t->longText('logs'); $t->longText('error')->nullable();
                $t->unsignedInteger('attempts')->default(0); $t->dateTime('started_at')->nullable(); $t->dateTime('finished_at')->nullable();
            },
            'ips'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->string('address',45); $t->unsignedInteger('prefix');
                $t->unsignedInteger('pool_id')->default(0)->index(); $t->unsignedTinyInteger('family')->default(4);
                $t->string('gateway',45)->nullable(); $t->string('state',16)->default('free'); $t->unsignedInteger('service_id')->nullable()->index();
                $t->unique('address');
            },
            'ip_pools'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->string('name',120); $t->unsignedTinyInteger('family');
                $t->unsignedInteger('prefix'); $t->string('gateway',45)->nullable();
                $t->string('bridge',64)->default(''); $t->unsignedInteger('vlan')->nullable();
                $t->string('dns',255)->default(''); $t->unsignedInteger('mtu')->default(1500);
            },
            'image_groups'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->string('name',120);
                $t->unique(['server_id','name']);
            },
            'traffic'=>function ($t) {
                $t->unsignedInteger('service_id')->unique(); $t->string('period',7);
                $t->unsignedTinyInteger('anchor_day')->nullable();
                $t->unsignedBigInteger('incoming')->default(0); $t->unsignedBigInteger('outgoing')->default(0);
                $t->unsignedBigInteger('last_in')->default(0); $t->unsignedBigInteger('last_out')->default(0);
                $t->unsignedInteger('last_vmid')->nullable(); $t->unsignedBigInteger('last_uptime')->default(0);
                $t->unsignedBigInteger('sample_at')->default(0); $t->string('restriction',16)->default('normal');
                $t->unsignedInteger('reset_invoice')->default(0);
            },
            'traffic_resets'=>function ($t) {
                $t->unsignedInteger('server_id'); $t->unsignedInteger('service_id')->index();
                $t->string('instance_uuid',40); $t->unsignedInteger('client_id'); $t->unsignedInteger('currency_id');
                $t->unsignedInteger('invoice_id')->unique(); $t->unsignedInteger('item_id'); $t->string('item_signature',64)->default('');
                $t->decimal('amount',10,2); $t->string('period',7); $t->string('state',12);
                $t->unsignedBigInteger('expires_at')->nullable()->index();
                $t->unsignedInteger('task_id')->nullable();
                $t->index(['state','period']);
            },
            'logs'=>function ($t) {
                $t->unsignedInteger('server_id')->default(0)->index(); $t->unsignedInteger('service_id')->default(0);
                $t->string('level',16); $t->text('message');
            },
            'cache'=>function ($t) {
                $t->string('cache_key',160)->unique(); $t->longText('value'); $t->unsignedBigInteger('expires_at');
            },
            'backups'=>function ($t) {
                $t->unsignedInteger('server_id')->index(); $t->unsignedInteger('service_id')->index();
                $t->unsignedInteger('task_id')->unique(); $t->unsignedInteger('source_vmid');
                $t->boolean('automatic'); $t->string('volume',512); $t->unsignedBigInteger('size');
                $t->longText('manifest');
            },
        ];
        foreach ($tables as $name=>$definition) {
            if ($schema->hasTable('mod_whmpve_'.$name)) { continue; }
            $schema->create('mod_whmpve_'.$name, function ($t) use ($name,$definition) {
                if ($name !== 'servers') { $t->increments('id'); }
                $definition($t); $t->dateTime('created_at');
                if ($name !== 'logs') { $t->dateTime('updated_at'); }
            });
        }
        Upgrade::run();
        Upgrade::features();
        Upgrade::disks();
        if (!$schema->hasColumn('mod_whmpve_images','description')) {
            $schema->table('mod_whmpve_images',fn($t)=>$t->string('description',500)->default(''));
        }
        if (!$schema->hasColumn('mod_whmpve_plans','traffic_reset_prices')) {
            $schema->table('mod_whmpve_plans',fn($t)=>$t->text('traffic_reset_prices')->nullable());
        }
        if (!$schema->hasColumn('mod_whmpve_traffic','reset_invoice')) {
            $schema->table('mod_whmpve_traffic',fn($t)=>$t->unsignedInteger('reset_invoice')->default(0));
        }
        if (!$schema->hasColumn('mod_whmpve_traffic_resets','item_signature')) {
            $schema->table('mod_whmpve_traffic_resets',fn($t)=>$t->string('item_signature',64)->default(''));
        }
        if (!$schema->hasColumn('mod_whmpve_instances','auto_backup_since')) {
            $schema->table('mod_whmpve_instances',fn($t)=>$t->dateTime('auto_backup_since')->nullable());
        }
        if (!$schema->hasColumn('mod_whmpve_traffic','anchor_day')) {
            $schema->table('mod_whmpve_traffic',fn($t)=>$t->unsignedTinyInteger('anchor_day')->nullable());
        }
        if (!$schema->hasColumn('mod_whmpve_traffic_resets','expires_at')) {
            $schema->table('mod_whmpve_traffic_resets',fn($t)=>$t->unsignedBigInteger('expires_at')->nullable()->index());
        }
        if (!$schema->hasColumn('mod_whmpve_traffic_resets','addon_id')) {
            $schema->table('mod_whmpve_traffic_resets',function($t) {
                $t->unsignedInteger('addon_id')->nullable()->index(); $t->text('effect')->nullable();
                $t->boolean('stock_held')->default(false); $t->unsignedInteger('native_addon_id')->nullable();
            });
        }
        if (!$schema->hasColumn('mod_whmpve_ips','hold_invoice_id')) {
            $schema->table('mod_whmpve_ips',fn($t)=>$t->unsignedInteger('hold_invoice_id')->nullable()->index());
        }
        Addons::migrate();
        Traffic::migrateCycles();
    }

    public static function deactivate(): array { return ['status'=>'success','description'=>Lang::text('message.disabled')]; }
}
