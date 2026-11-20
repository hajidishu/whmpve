<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Delivery
{
    private static array $duplicateNative = [];

    public static function nativeSuccess(int $service): string
    {
        $task=DB::table('mod_whmpve_tasks')->where('service_id',$service)->where('action','create')
            ->orderByDesc('id')->first(['id','context']);
        $context=Util::unpack($task->context ?? null);
        $welcome=$context['welcome'] ?? null;
        if (!$welcome) { return 'success'; }

        if ($welcome['state']==='queued' && !$welcome['attempts']) {
            $context['welcome']['state']='native';
            if (self::update($task,$context)) { return 'success'; }
        }
        self::$duplicateNative[$service]=true;
        return 'success';
    }

    public static function suppressNative(int $service,string $name): bool
    {
        if (empty(self::$duplicateNative[$service]) || self::template($service)!==$name) { return false; }
        unset(self::$duplicateNative[$service]);
        return true;
    }

    private static function template(int $service,?int $server=null): ?string
    {
        $query=DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
            ->join('tblemailtemplates as e','e.id','=','p.welcomeemail')
            ->where('h.id',$service)->where('h.domainstatus','Active')->where('p.servertype','whmpve')
            ->where('e.type','product')->where('e.disabled',0);
        if ($server!==null) { $query->where('h.server',$server); }
        return $query->value('e.name');
    }

    private static function update(object $task,array $context): bool
    {
        return DB::table('mod_whmpve_tasks')->where('id',$task->id)->where('context',$task->context)
            ->update(['context'=>Util::json($context)])===1;
    }

    public static function sendDue(): void
    {
        $due="CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.welcome.at')) AS UNSIGNED)";
        $tasks=DB::table('mod_whmpve_tasks')->where('action','create')->where('status','success')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.welcome.state')) IN ('queued','sending')")
            ->whereRaw($due.' <= ?',[time()])->orderByRaw($due)->orderBy('id')->limit(5)->get(['id','service_id','server_id','context']);
        foreach ($tasks as $task) {
            $context=Util::unpack($task->context);
            $welcome=&$context['welcome'];
            $service=(int)$task->service_id;
            $server=(int)$task->server_id;
            if ($welcome['state']==='sending') {
                $welcome['state']='failed';
                if (self::update($task,$context)) {
                    Util::log('交付邮件发送结果待确认，请检查 WHMCS 邮件日志。',$server,$service,'warning');
                }
                continue;
            }
            $template=self::template($service,$server);
            $welcome['state']=$template ? 'sending' : 'skipped';
            $welcome['at']=time()+600;
            if ($template) { $welcome['attempts']++; }
            if (!self::update($task,$context) || !$template) { continue; }

            $task->context=Util::json($context);
            unset(self::$duplicateNative[$service]);
            try {
                Util::moduleApi('SendEmail',['messagename'=>$template,'id'=>$service]);
                $welcome['state']='sent';
            } catch (\Throwable $e) {
                $welcome['state']=$welcome['attempts']<3 ? 'queued' : 'failed';
                Util::log('交付邮件发送失败，请检查 WHMCS 邮件设置与日志。',$server,$service,'warning');
            }
            self::update($task,$context);
        }
    }
}
