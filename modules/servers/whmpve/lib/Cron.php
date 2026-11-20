<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Cron
{
    public static function run(): void
    {
        if (!Util::enabled() || !Schema::installed()) { return; }
        if (empty(DB::select('SELECT GET_LOCK(?,0) as acquired',['whmpve_cron'])[0]->acquired)) { return; }
        try {
            foreach ([Jobs::reconcilePayments(...), Purchases::reconcile(...), Backups::schedule(...), Console::cleanup(...)] as $step) {
                try { $step(); } catch (\Throwable $e) { Util::log($e->getMessage()); }
            }
            $deadline=microtime(true)+24;
            $cursor=DB::table('mod_whmpve_cache')->where('cache_key','cron:server')->value('value');
            do {
                $queued=DB::table('mod_whmpve_tasks')->where('server_id','>',0)->whereIn('status',['queued','running'])
                    ->distinct()->pluck('server_id')->all();
                $traffic=array_map('intval',Traffic::dueServers());
                $servers=array_unique(array_map('intval',array_merge($queued,$traffic)));
                usort($servers,fn(int $a,int $b)=>[$a<=(int)$cursor,$a]<=>[$b<=(int)$cursor,$b]);
                $worked=false;
                foreach ($servers as $server) {
                    if (microtime(true)>=$deadline) { return; }
                    DB::table('mod_whmpve_cache')->updateOrInsert(['cache_key'=>'cron:server'],[
                        'value'=>(string)$server,'expires_at'=>0,'created_at'=>Util::now(),'updated_at'=>Util::now(),
                    ]);
                    $cursor=$server;
                    try {
                        if (Jobs::run(null,$server,$deadline)) { $worked=true; }
                        if (in_array($server,$traffic,true) && microtime(true)<$deadline && Traffic::poll($server,false,$deadline)) { $worked=true; }
                    } catch (\Throwable $e) { Util::log($e->getMessage(),$server); }
                }
                if ($worked && microtime(true)<$deadline) { usleep(350000); }
            } while ($worked && microtime(true)<$deadline);
        } finally { DB::select('SELECT RELEASE_LOCK(?)',['whmpve_cron']); }
    }
}
