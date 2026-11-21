<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Jobs
{
    public const ACTIONS=['create','start','shutdown','stop','reboot','reset','reinstall','suspend','unsuspend','terminate','change_package','edit_config','addon_apply','password','lxc_password_reset','traffic','traffic_reset','backup','backup_restore','backup_delete'];
    public const POWER_ACTIONS=['start','shutdown','stop','reboot','reset'];
    private static function paymentServices($query)
    {
        return $query->join('tblproducts as p','p.id','=','h.packageid')
            ->where('p.servertype','whmpve')->where('p.autosetup','payment')->whereIn('h.domainstatus',['Pending','Active']);
    }

    public static function paidInvoice(int $invoice,?int $serverId=null): void
    {
        if (!Util::enabled()) { return; }
        if (DB::table('tblinvoices')->where('id',$invoice)->value('status')!=='Paid') { return; }
        $ids=self::paymentServices(DB::table('tblinvoiceitems as i')->join('tblhosting as h','h.id','=','i.relid'))
            ->where('i.invoiceid',$invoice)->where('i.type','Hosting')->distinct()->pluck('h.id');
        foreach ($ids as $id) {
            try {
                $instance=Catalog::freeze(Catalog::params((int)$id));
                if ($serverId && (int)$instance['server_id']!==$serverId) { continue; }
                if ($instance['lifecycle']!=='pending' || $instance['vmid']) { continue; }
                self::queuePaidInstance($instance,(int)DB::table('tblhosting')->where('id',$id)->value('server'));
            } catch (\Throwable $e) { Util::log($e->getMessage(),$serverId ?? 0,(int)$id); }
        }
    }

    public static function reconcilePayments(?int $serverId=null): void
    {
        if (!Util::enabled()) { return; }
        $key='cron:payment:'.($serverId ?? 0);
        $cursor=(int)DB::table('mod_whmpve_cache')->where('cache_key',$key)->value('value');
        $query=self::paymentServices(DB::table('mod_whmpve_instances as m')->join('tblhosting as h','h.id','=','m.service_id'))
            ->where('m.lifecycle','pending')->whereNull('m.vmid')
            ->whereNotExists(fn($tasks)=>$tasks->select('t.id')->from('mod_whmpve_tasks as t')
                ->whereColumn('t.service_id','m.service_id')->where('t.action','create'))
            ->whereExists(fn($invoices)=>$invoices->select('i.id')->from('tblinvoiceitems as i')
                ->join('tblinvoices as n','n.id','=','i.invoiceid')->whereColumn('i.relid','m.service_id')
                ->where('i.type','Hosting')->where('n.status','Paid'));
        if ($serverId) { $query->where('m.server_id',$serverId); }
        $rows=$query->orderByRaw('CASE WHEN m.id<=? THEN 1 ELSE 0 END',[$cursor])->orderBy('m.id')->limit(20)
            ->get(['m.id','m.service_id','m.server_id','m.uuid','h.server']);
        foreach ($rows as $instance) {
            $cursor=(int)$instance->id;
            try { self::queuePaidInstance((array)$instance,(int)$instance->server); }
            catch (\Throwable $e) { Util::log($e->getMessage(),(int)$instance->server_id,(int)$instance->service_id); }
        }
        if ($rows->count()) {
            DB::table('mod_whmpve_cache')->updateOrInsert(['cache_key'=>$key],[
                'value'=>(string)$cursor,'expires_at'=>0,'created_at'=>Util::now(),'updated_at'=>Util::now(),
            ]);
        }
    }

    private static function queuePaidInstance(array $instance,int $server): void
    {
        $service=(int)$instance['service_id'];
        if (!$server) { Util::moduleApi('UpdateClientProduct',['serviceid'=>$service,'serverid'=>$instance['server_id']]); }
        else { Catalog::assertServerBinding($instance,$server); }
        self::enqueue($service,'create','create-'.$instance['uuid'],[],'payment');
    }

    public static function requireProvisioning(array $instance,bool $lock=false): void
    {
        $query=DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
            ->where('h.id',$instance['service_id'])->where('p.servertype','whmpve');
        $native=($lock ? $query->lockForUpdate() : $query)->first(['h.domainstatus','h.server']);
        if (!$native || !in_array($native->domainstatus,['Pending','Active'],true)) {
            throw Fault::localized('message.provisioning_was_stopped_because_the_whmcs_service',409);
        }
        Catalog::assertServerBinding($instance,(int)$native->server);
    }
    public static function instance(int $service): array
    {
        $r=DB::table('mod_whmpve_instances')->where('service_id',$service)->first();
        if (!$r) { throw Fault::localized('message.this_service_does_not_have_an_instance',404); }
        return (array)$r;
    }
    public static function unfinishedNetworkEdit(int $service): bool
    {
        $tasks=DB::table('mod_whmpve_tasks')->where('service_id',$service)->whereIn('action',['edit_config','addon_apply'])
            ->orderByDesc('id')->get(['status','context']);
        foreach ($tasks as $task) {
            $context=Util::unpack($task->context);
            if (!empty($context['network_edit'])) { return empty($context['edit_committed']) && $task->status!=='success'; }
        }
        return false;
    }

    private static function checkNetworkEdit(int $service,string $action): void
    {
        if (in_array($action,['start','reboot','reset','reinstall','unsuspend','change_package','backup_restore','lxc_password_reset'],true) && self::unfinishedNetworkEdit($service)) {
            throw Fault::localized('message.complete_or_save_the_instances_network_configuration',409);
        }
    }
    private static function checkPasswordReset(int $service,int $retry=0): void
    {
        $tasks=DB::table('mod_whmpve_tasks')->where('service_id',$service)->where('action','lxc_password_reset')
            ->where('status','failed')->where('id','!=',$retry)->pluck('context');
        foreach ($tasks as $context) {
            if (!empty(Util::unpack($context)['password_reset_pending'])) {
                throw Fault::localized('message.the_previous_password_reset_has_pending_resources',409);
            }
        }
    }
    private static function checkReinstallRecovery(int $service,int $vmid,int $retry=0): void
    {
        $tasks=DB::table('mod_whmpve_tasks')->where('service_id',$service)->where('action','reinstall')
            ->where('status','failed')->where('id','!=',$retry)->pluck('context');
        foreach ($tasks as $context) {
            $ctx=Util::unpack($context);
            if (!empty($ctx['new_vmid']) && (int)$ctx['new_vmid']!==$vmid
                && (!empty($ctx['reinstall_cutover']) || !empty($ctx['done']['old-shutdown']) || !empty($ctx['ops']['old-shutdown']))) {
                throw Fault::localized('message.the_previous_reinstall_has_data_disks_to',409);
            }
        }
    }
    public static function enqueue(int $service,string $action,string $key,array $payload=[],string $actor='system'): array
    {
        if (!Util::enabled()) { throw Fault::localized('message.activate_the_whmpve_add_on_module_first',503); }
        if (!in_array($action,self::ACTIONS,true)) { throw Fault::localized('message.unsupported_action'); }
        if (!preg_match('/^[a-zA-Z0-9_-]{8,120}$/',$key)) { throw Fault::localized('message.invalid_request_id'); }
        $request=hash('sha256',$service.':'.$action.':'.$key);
        return DB::connection()->transaction(function () use ($service,$action,$request,$payload,$actor) {
            $instance=DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first();
            if (!$instance) { throw Fault::localized('message.instance_record_not_found',404); }
            $old=DB::table('mod_whmpve_tasks')->where('request_key',$request)->first();
            if ($old) {
                $saved=Util::unpack($old->payload);
                $submitted=$payload;
                if (in_array($action,['reinstall','password','lxc_password_reset'],true) && isset($saved['encrypted_password'],$submitted['encrypted_password'])
                    && hash_equals(decrypt($saved['encrypted_password']),decrypt($submitted['encrypted_password']))) {
                    unset($saved['encrypted_password'],$submitted['encrypted_password']);
                }
                if ($saved!==$submitted) { throw Fault::localized('message.a_request_id_cannot_be_reused_with',409); }
                return self::public((array)$old);
            }
            if (DB::table('mod_whmpve_tasks')->where('service_id',$service)->whereIn('status',['queued','running'])->exists()) { throw Fault::localized('message.an_instance_task_is_already_running_wait',409); }
            if ($instance->lifecycle==='terminated' && $action!=='backup_delete') { throw Fault::localized('message.the_service_instance_has_been_deleted',409); }
            self::checkNetworkEdit($service,$action);
            self::checkPasswordReset($service);
            self::checkReinstallRecovery($service,(int)$instance->vmid);
            if ($action==='create' && $instance->vmid && $instance->lifecycle==='active') { throw Fault::localized('message.the_service_is_already_provisioned',409); }
            if ($action==='create') { self::requireProvisioning((array)$instance,true); }
            if (!in_array($action,['create','terminate','unsuspend','suspend','traffic','traffic_reset','backup_delete'],true)) {
                $native=DB::table('tblhosting')->where('id',$service)->first(['domainstatus','suspendreason']);
                $allowed=$native && ($action==='edit_config'
                    ? InstanceConfig::editable($instance->lifecycle,$native->domainstatus,$native->suspendreason)
                    : $instance->lifecycle==='active' && $native->domainstatus==='Active');
                if (!$allowed) { throw Fault::localized('message.this_action_is_unavailable_for_the_current',403); }
            }
            $spec=Util::unpack($instance->applied ?: $instance->desired);
            if (in_array($action,['start','reboot','reset','reinstall','unsuspend','backup_restore','lxc_password_reset'],true)
                && Traffic::target($spec,Traffic::row($service))==='suspended') { throw Fault::localized('message.the_traffic_quota_for_this_period_is',403); }
            if ($action==='reinstall') {
                $image=Util::scoped('images',(int)($payload['image_id'] ?? 0),(int)$instance->server_id);
                if (!Catalog::imageAllowed($image,$spec)) { throw Fault::localized('message.this_plan_does_not_allow_the_selected'); }
                if (str_starts_with($actor,'client:') && ($spec['reinstall_limit'] ?? 0)>0
                    && Catalog::reinstallCount($service)>=$spec['reinstall_limit']) { throw Fault::localized('message.the_monthly_client_area_reinstall_limit_has',429); }
                if (($payload['confirm_hostname'] ?? '')!==$instance->hostname) { throw Fault::localized('message.enter_the_full_hostname_to_confirm_erasing'); }
            }
            if ($action==='password' && $instance->kind!=='qemu') { throw Fault::localized('message.this_instance_does_not_support_password_resets'); }
            if ($action==='lxc_password_reset' && $instance->kind!=='lxc') { throw Fault::localized('message.this_instance_is_not_an_lxc_container'); }
            if ($action==='backup' && empty($payload['automatic'])) { Backups::checkCreate((array)$instance,false); }
            if (in_array($action,['backup_restore','backup_delete'],true)) {
                Backups::record((array)$instance,(int)($payload['backup_id'] ?? 0));
            }
            if (in_array($action,['reinstall','backup_restore','lxc_password_reset'],true)) {
                foreach (DB::table('mod_whmpve_tasks')->where('service_id',$service)->whereIn('action',['reinstall','backup_restore'])
                    ->where('status','failed')->get(['context']) as $failed) {
                    $ctx=Util::unpack($failed->context);
                    if (!empty($ctx['new_vmid']) && empty($ctx['done']['remove-old'])) {
                        throw Fault::localized('message.the_previous_os_replacement_has_pending_resources',409);
                    }
                }
            }
            $id=DB::table('mod_whmpve_tasks')->insertGetId(['server_id'=>$instance->server_id,'service_id'=>$service,'action'=>$action,'request_key'=>$request,'actor'=>$actor,
                'status'=>'queued','stage'=>Lang::text('ui.queued'),'payload'=>Util::json($payload),'context'=>'{}','logs'=>'[]',
                'created_at'=>Util::now(),'updated_at'=>Util::now()]);
            return self::public(Util::row('tasks',$id));
        });
    }
    public static function public(array $task): array
    {
        $visible=array_intersect_key($task,array_flip(['id','server_id','service_id','action','status','stage','stage_number','stage_total','error','created_at','updated_at','finished_at']));
        if ($task['status']==='success') { $visible['stage']=Lang::text('message.completed'); }
        elseif (!$task['stage_number']) { $visible['stage']=Lang::text('ui.queued'); }
        $field=$task['status']==='failed' && !empty($task['error']) ? 'error' : 'stage';
        foreach (array_reverse(Util::unpack($task['logs'] ?? '')) as $entry) {
            if (($entry['field'] ?? 'stage')===$field && ($visible[$field] ?? null)===$entry['message']) {
                $visible[$field]=Lang::display($entry['message'],$entry['translation'] ?? null);
                break;
            }
        }
        return $visible;
    }
    public static function retry(int $id,string $actor): array
    {
        return DB::connection()->transaction(function () use ($id,$actor) {
            $task=Util::row('tasks',$id);
            $instance=DB::table('mod_whmpve_instances')->where('service_id',$task['service_id'])->lockForUpdate()->first();
            $task=(array)(DB::table('mod_whmpve_tasks')->where('id',$id)->lockForUpdate()->first()
                ?? throw Fault::localized('message.task_not_found',404));
            if (!$instance || ($instance->lifecycle==='terminated' && $task['action']!=='backup_delete')) { throw Fault::localized('message.the_instance_has_been_deleted'); }
            if ($task['status']!=='failed') { throw Fault::localized('message.only_failed_tasks_can_be_retried'); }
            if (DB::table('mod_whmpve_tasks')->where('service_id',$task['service_id'])->whereIn('status',['queued','running'])->exists()) { throw Fault::localized('message.a_task_is_already_in_progress',409); }
            self::checkNetworkEdit((int)$task['service_id'],$task['action']);
            self::checkPasswordReset((int)$task['service_id'],$id);
            self::checkReinstallRecovery((int)$task['service_id'],(int)$instance->vmid,$id);
            if (in_array($task['action'],['edit_config','addon_apply'],true) && DB::table('mod_whmpve_tasks')->where('service_id',$task['service_id'])
                ->whereIn('action',['edit_config','addon_apply'])->where('id','>',$id)->where('stage_number','>=',2)->exists()) {
                throw Fault::localized('message.a_newer_configuration_task_exists_check_the',409);
            }
            $context=Util::unpack($task['context']);
            if ($task['action']==='reinstall' && isset($context['reinstall_rolled_back'])) { $context=[]; }
            if ($task['action']==='create' && $instance->vmid && Pve::vmidAlreadyUsed((string)$task['error'],(int)$instance->vmid)) {
                $context['initial_allocation']=true;
            }
            foreach ($context as $key=>$value) { if (str_ends_with($key,'-since')) { $context[$key]=time(); } }
            unset($context['power_deadline']);
            $values=['status'=>'queued','error'=>null,'finished_at'=>null,'context'=>Util::json($context),'updated_at'=>Util::now()];
            if (str_starts_with($actor,'admin:')) {
                $logs=Util::unpack($task['logs']);
                $logs[]=['time'=>Util::now(),'message'=>Lang::text('ui.administrator').(int)substr($actor,6).Lang::text('message.retried_this_task')];
                $values['logs']=Util::json($logs);
            }
            DB::table('mod_whmpve_tasks')->where('id',$id)->update($values);
            return self::public(Util::row('tasks',$id));
        });
    }
    public static function kickPower(array $task): array
    {
        if (!in_array($task['action'],self::POWER_ACTIONS,true) || $task['status']!=='queued') { return $task; }
        self::run((int)$task['id'],(int)$task['server_id'],microtime(true)+3,true);
        return self::public(Util::row('tasks',(int)$task['id']));
    }

    public static function onbootServers(): array
    {
        $keys=DB::table('mod_whmpve_cache')->where('cache_key','like','onboot:%')->where('expires_at','<=',time())->pluck('cache_key')->all();
        return array_map(fn($key)=>(int)substr($key,7),$keys);
    }

    public static function syncOnboot(int $server,float $deadline): bool
    {
        $lock='whmpve_server_'.$server;
        if (empty(DB::select('SELECT GET_LOCK(?,0) as acquired',[$lock])[0]->acquired)) { return false; }
        try {
            $query=DB::table('mod_whmpve_cache')->where('cache_key','onboot:'.$server)->where('expires_at','<=',time());
            $value=$query->value('value');
            if (!$value) { return false; }
            $state=Util::unpack($value);
            $job=$query->where('value',$value);
            $instance=DB::table('mod_whmpve_instances')->where('server_id',$server)->where('id','>',$state['cursor'])
                ->whereNotNull('vmid')->whereIn('lifecycle',['active','suspended'])->orderBy('id')->first();
            if (!$instance) {
                if ($state['retry']) {
                    $job->update(['value'=>Util::json(array_replace($state,['cursor'=>0,'retry'=>false])),
                        'expires_at'=>time()+300,'updated_at'=>Util::now()]);
                } else { $job->delete(); }
                return false;
            }
            $service=(int)$instance->service_id;
            $state['cursor']=(int)$instance->id;
            try {
                if (DB::table('mod_whmpve_tasks')->where('service_id',$service)->whereIn('status',['queued','running'])->exists()) {
                    throw new Pending(Lang::text('message.waiting_for_the_instance_task_to_finish'));
                }
                self::checkPasswordReset($service);
                self::checkReinstallRecovery($service,(int)$instance->vmid);
                $native=DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
                    ->where('h.id',$service)->where('p.servertype','whmpve')->first(['h.server','h.domainstatus']);
                if ($native) {
                    Catalog::assertServerBinding((array)$instance,(int)$native->server);
                    $pve=new Pve($server,$deadline,true);
                    $owned=$pve->owned((array)$instance) ?? throw IdentityMismatch::localized('message.the_instance_was_not_found_on_pve',404);
                    $spec=Util::unpack($instance->applied ?: $instance->desired);
                    $enabled=!empty($pve->settings['onboot']) && $instance->lifecycle==='active' && $native->domainstatus==='Active'
                        && !self::unfinishedNetworkEdit($service) && Traffic::target($spec,Traffic::row($service))!=='suspended';
                    if ($enabled && DB::table('mod_whmpve_tasks')->where('service_id',$service)->where('action','backup_restore')
                        ->where('status','failed')->whereRaw("JSON_CONTAINS_PATH(context, 'one', '$.ops.\"restore-start\"', '$.done.\"restore-start\"')")
                        ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$.old_vmid')) AS UNSIGNED) = ?",[$instance->vmid])->exists()) {
                        throw Fault::localized('message.the_previous_backup_restore_has_instances_to');
                    }
                    $pve->setOnboot($owned,$enabled);
                }
            } catch (Pending) {
                $state['retry']=true;
            } catch (\Throwable $e) {
                $state['retry']=$state['retry'] || $e instanceof UnknownPveRequest;
                Util::log(Lang::text('message.could_not_sync_the_automatic_startup_setting').$e->getMessage(),$server,$service,'warning');
            }
            $job->update(['value'=>Util::json($state),'updated_at'=>Util::now()]);
            return true;
        } finally { DB::select('SELECT RELEASE_LOCK(?)',[$lock]); }
    }

    public static function run(?int $id=null,?int $serverId=null,?float $deadline=null,bool $foreground=false): bool
    {
        if (!Util::enabled()) { return false; }
        $serverId=$serverId ?: (int)DB::table('mod_whmpve_tasks')->where('id',$id)->value('server_id');
        if (!$serverId) { return false; }
        $lockName='whmpve_server_'.$serverId;
        $lock=DB::select('SELECT GET_LOCK(?, 0) as acquired',[$lockName]);
        if (empty($lock[0]->acquired)) { return false; }
        try {
            $query=DB::table('mod_whmpve_tasks')->where('server_id',$serverId)->whereIn('status',['queued','running']);
            if ($id && !$foreground) { $query->where('id',$id); }
            $task=$query->orderBy('id')->first();
            if (!$task || ($foreground && (int)$task->id!==$id)) { return false; }
            DB::table('mod_whmpve_tasks')->where('id',$task->id)->update(['status'=>'running',
                'started_at'=>$task->started_at ?: Util::now(),'updated_at'=>Util::now()]);
            try {
                $job=new Job((array)$task,$deadline,$foreground);
                $job->execute();
                DB::table('mod_whmpve_instances')->where('service_id',$task->service_id)->update(['last_error'=>null,'updated_at'=>Util::now()]);
                $binding=self::instance((int)$task->service_id);
                DB::table('mod_whmpve_cache')->where('cache_key','like','metrics:'.$binding['server_id'].':'.$binding['uuid'].':%')->delete();
                DB::table('mod_whmpve_tasks')->where('id',$task->id)->update(['status'=>'success','error'=>null,'stage'=>Lang::text('message.completed'),
                    'stage_number'=>DB::raw('stage_total'),'finished_at'=>Util::now(),'updated_at'=>Util::now(),'attempts'=>DB::raw('attempts+1')]);
            } catch (Pending $e) {
                DB::table('mod_whmpve_tasks')->where('id',$task->id)->update(['status'=>'queued','updated_at'=>Util::now()]);
            } catch (\Throwable $e) {
                $message=mb_substr($e->getMessage(),0,1000);
                $failed=['status'=>'failed','error'=>$message,'finished_at'=>Util::now(),'updated_at'=>Util::now(),'attempts'=>DB::raw('attempts+1')];
                if ($e instanceof Fault && $e->translation) {
                    $logs=Util::unpack(DB::table('mod_whmpve_tasks')->where('id',$task->id)->value('logs'));
                    $logs[]=['time'=>Util::now(),'message'=>$message,'field'=>'error','translation'=>$e->translation];
                    $failed['logs']=Util::json(array_slice($logs,-100));
                }
                DB::table('mod_whmpve_tasks')->where('id',$task->id)->update($failed);
                DB::table('mod_whmpve_instances')->where('service_id',$task->service_id)->update(['last_error'=>$message,'updated_at'=>Util::now()]);
                if (in_array($task->action,['traffic_reset','addon_apply'],true) && $task->attempts+1===Purchases::ATTEMPTS) {
                    $invoice=(int)Util::unpack($task->payload)['invoice_id'];
                    Util::log(Lang::text('message.traffic_reset_invoice').$invoice.Lang::text('message.failed_check_task').$task->id.Lang::text('message.before_retrying_or_verifying_the_refund'),
                        (int)$task->server_id,(int)$task->service_id,'warning');
                }
            }
            return true;
        } finally { DB::select('SELECT RELEASE_LOCK(?)',[$lockName]); }
    }
    public static function module(array $params,string $action,array $payload=[]): string
    {
        try {
            $instance=Catalog::freeze($params);
            if ($action==='create') {
                self::requireProvisioning($instance);
                if ($instance['lifecycle']==='active' && $instance['vmid']) {
                    (new Pve((int)$instance['server_id']))->owned($instance) ?: throw Fault::localized('message.the_provisioned_instance_is_missing_from_pve');
                    return Delivery::nativeSuccess((int)$params['serviceid']);
                }
            }
            if ($action==='terminate' && $instance['lifecycle']==='terminated') { return 'success'; }
            $key=$action==='create' ? 'create-'.$instance['uuid'] : $action.'-'.bin2hex(random_bytes(8));
            if ($action==='change_package') {
                $resolved=Catalog::resolve($params);
                if ((int)$resolved['server']['id']!==(int)$instance['server_id']) { throw Fault::localized('message.the_server_cannot_be_changed_during_an'); }
                $payload['spec']=$resolved['spec']; $payload['plan_id']=$resolved['plan']['id'];
            }
            $task=self::enqueue((int)$params['serviceid'],$action,$key,$payload,'module');
            if ($task['status']==='failed') { return Lang::text('message.a_task_failed_review_it_in_tasks').$task['id']; }
            $deadline=microtime(true)+65;
            do {
                self::run((int)$task['id']); $task=Util::row('tasks',(int)$task['id']);
                if ($task['status']==='success') {
                    return $action==='create' ? Delivery::nativeSuccess((int)$params['serviceid']) : 'success';
                }
                if ($task['status']==='failed') { return $task['error']; }
                usleep(250000);
            } while (microtime(true)<$deadline);
            return Lang::text('message.request_pending',['task'=>$task['id']]);
        } catch (\Throwable $e) { return $e->getMessage(); }
    }
}

final class Job
{
    private array $ctx;
    private array $payload;
    private array $instance;
    private Pve $pve;
    public function __construct(private array $task,private readonly ?float $deadline=null,private readonly bool $foreground=false)
    {
        $this->ctx=Util::unpack($task['context']); $this->payload=Util::unpack($task['payload']);
        $this->instance=Jobs::instance((int)$task['service_id']); $this->pve=new Pve((int)$this->instance['server_id'],$deadline,$foreground);
    }
    private function save(): void
    {
        DB::table('mod_whmpve_tasks')->where('id',$this->task['id'])->update(['context'=>Util::json($this->ctx),'updated_at'=>Util::now()]);
    }
    private function stage(string $key,int $number,int $total,array $values=[]): void
    {
        $label=Lang::text($key,$values,'chinese');
        $translation=['key'=>$key,'values'=>$values];
        DB::table('mod_whmpve_tasks')->where('id',$this->task['id'])->update(['stage'=>$label,'stage_number'=>$number,'stage_total'=>$total,'updated_at'=>Util::now()]);
        $logs=Util::unpack(DB::table('mod_whmpve_tasks')->where('id',$this->task['id'])->value('logs'));
        if (!$logs || end($logs)['message']!==$label) {
            $logs[]=['time'=>Util::now(),'message'=>$label,'translation'=>$translation];
            DB::table('mod_whmpve_tasks')->where('id',$this->task['id'])->update(['logs'=>Util::json(array_slice($logs,-100))]);
        }
    }
    private function mutate(array $values): void
    {
        $values['updated_at']=Util::now(); DB::table('mod_whmpve_instances')->where('id',$this->instance['id'])->update($values);
        $this->instance=array_merge($this->instance,$values);
    }
    private function op(string $key,string $method,string $path,array $data=[],?array $worker=null): void
    {
        if (!empty($this->ctx['done'][$key])) { return; }
        $passwordReset=$this->task['action']==='lxc_password_reset';
        if (!empty($this->ctx['ops'][$key]['uncertain'])) {
            if ($worker) { $this->recoverWorker($key,$worker); }
            elseif ($passwordReset) { $this->resumePasswordResetOp($key,$method,$path,$data); }
            else { throw Fault::localized('message.the_last_request_has_an_unknown_outcome'); }
            if (!empty($this->ctx['done'][$key])) { return; }
        }
        if (empty($this->ctx['ops'][$key]['upid'])) {
            if (in_array($key,['initial-create','initial-start'],true)) { Jobs::requireProvisioning($this->instance); }
            $previous=$this->ctx['ops'][$key] ?? null;
            $retrying=!empty($previous['retried']);
            $this->ctx['ops'][$key]=['uncertain'=>true,'since'=>time(),'retried'=>$retrying]; $this->save();
            try { $result=$this->pve->request($method,$path,$data); }
            catch (Pending $e) {
                if ($previous===null) {
                    unset($this->ctx['ops'][$key]);
                    if (!$this->ctx['ops']) { unset($this->ctx['ops']); }
                } else { $this->ctx['ops'][$key]=$previous; }
                $this->save(); throw $e;
            }
            catch (Fault $e) {
                if (($worker || $passwordReset || in_array($key,['quota-resume','quota-resume-cancelled'],true)) && $e instanceof UnknownPveRequest) {
                    throw new Pending(Lang::text('message.checking_the_pve_task'));
                }
                if (str_contains($e->getMessage(),'PVE HTTP')) { unset($this->ctx['ops'][$key]); $this->save(); }
                throw $e;
            }
            if (is_string($result) && str_starts_with($result,'UPID:')) {
                $parts=explode(':',$result); $this->ctx['ops'][$key]=['upid'=>$result,'node'=>$parts[1]]; $this->save();
            } else { $this->ctx['done'][$key]=true; $this->save(); return; }
        }
        $operation=$this->ctx['ops'][$key]; $deadline=microtime(true)+12;
        do {
            try {
                $status=$this->pve->request('GET','/nodes/'.rawurlencode($operation['node']).'/tasks/'.rawurlencode($operation['upid']).'/status');
            } catch (Fault $e) {
                if ($worker) { throw UnknownPveRequest::localized('message.review_required_could_not_read_the_submitted',409); }
                throw $e;
            }
            if (($status['status'] ?? '')==='stopped') {
                $exit=(string)($status['exitstatus'] ?? Lang::text('message.unknown_error'));
                $warning=$this->task['action']==='create' && preg_match('/^WARNINGS: [1-9][0-9]*$/D',$exit);
                if ($exit!=='OK' && !$warning) {
                    if (!empty($operation['recovered']) && ($worker[0] ?? '')==='qmclone') {
                        throw UnknownPveRequest::localized('message.review_required_the_clone_task_is_recorded',409);
                    }
                    if ($this->task['action']==='lxc_password_reset' && $key==='restore-archive') {
                        $this->ctx['password_reset_restore_submitted']=true;
                    }
                    unset($this->ctx['ops'][$key]); $this->save();
                    if ($key==='initial-create' && Pve::vmidAlreadyUsed($exit,(int)$this->instance['vmid'])) {
                        throw new VmidConflict(Lang::text('message.pve_rejected_creation').$exit,409);
                    }
                    throw new Fault(Lang::text('message.pve_task_failed').$exit);
                }
                $this->ctx['done'][$key]=true;
                if ($warning) {
                    DB::connection()->transaction(function() use($exit,$operation) {
                        Util::log(Lang::text('message.pve_provisioning_warning').$exit.' · '.$operation['upid'],
                            (int)$this->instance['server_id'],(int)$this->instance['service_id'],'warning');
                        $this->save();
                    });
                } else {
                    $this->save();
                }
                return;
            }
            if ($this->deadline!==null && (!$this->foreground || microtime(true)+0.25>=$this->deadline)) { break; }
            usleep($this->foreground ? 250000 : 700000);
        } while (microtime(true)<$deadline);
        throw new Pending(Lang::text('message.pve_task_in_progress'));
    }

    private function recoverWorker(string $key,array $worker): void
    {
        [$type,$id,$node]=$worker;
        $since=(int)$this->ctx['ops'][$key]['since'];
        try { $upid=$this->pve->findWorker($type,(string)$id,$since,$node); }
        catch (Fault $e) { throw new UnknownPveRequest(Lang::text('message.review_required_could_not_safely_verify_the').$e->getMessage(),409); }
        if (!$upid) {
            if (time()<$since+60) { throw new Pending(Lang::text('message.checking_the_pve_task')); }
            throw UnknownPveRequest::localized('message.review_required_the_pve_request_was_submitted',409);
        }
        $this->ctx['ops'][$key]=['upid'=>$upid,'node'=>$node,'recovered'=>true];
        $this->save();
    }

    private function resumePasswordResetOp(string $key,string $method,string $path,array $data): void
    {
        $since=(int)$this->ctx['ops'][$key]['since'];
        if ($method==='PUT') {
            if (!empty($this->ctx['ops'][$key]['retried'])) {
                unset($this->ctx['ops'][$key]); $this->save();
                throw Fault::localized('message.two_consecutive_pve_configuration_requests_returned_no');
            }
            $this->ctx['ops'][$key]=['retried'=>true,'since'=>$since]; $this->save();
            return;
        }
        if ($key==='backup') {
            [$type,$id]=['vzdump',(string)$data['vmid']];
        } elseif ($key==='restore-archive') {
            [$type,$id]=['vzrestore',(string)$data['vmid']];
        } elseif ($key==='delete-reset-archive') {
            [$type,$id]=['imgdel',$this->ctx['source_vmid'].'@'.$this->ctx['backup_storage']];
        } elseif (preg_match('#/lxc/(\d+)(?:/status/(start|stop|shutdown))?$#D',$path,$match)) {
            [$type,$id]=['vz'.($match[2] ?? 'destroy'),$match[1]];
        } else {
            throw Fault::localized('message.could_not_verify_the_last_pve_request',409);
        }
        $upid=$this->pve->findWorker($type,$id,$since);
        if ($upid) {
            $this->ctx['ops'][$key]=['upid'=>$upid,'node'=>$this->pve->settings['node']]; $this->save();
            return;
        }
        if (time()<$since+60) { throw new Pending(Lang::text('message.waiting_for_pve_to_record_the_task')); }
        if ($key==='backup' && $this->findTaskBackup()) {
            $this->ctx['done'][$key]=true; $this->save();
            return;
        }
        if ($key==='restore-archive') {
            $copy=$this->passwordResetCopy();
            if ($copy && !empty($copy['config']['lock'])) { throw Fault::localized('message.pve_is_still_locking_the_restored_copy',409); }
            if ($copy) { $this->ctx['password_reset_restore_submitted']=true; }
        }
        unset($this->ctx['ops'][$key]); $this->save();
        throw Fault::localized('message.no_task_was_found_for_the_last');
    }
    private function power(string $command,?int $vmid=null,string $key='power'): void
    {
        $reinstall=$this->task['action']==='reinstall';
        if (!empty($this->ctx['power_confirmed'][$key]) && !$reinstall && $this->task['action']!=='lxc_password_reset') { return; }
        if ($command==='start' && Jobs::unfinishedNetworkEdit((int)$this->instance['service_id'])) {
            throw Fault::localized('message.complete_the_instances_network_configuration_first');
        }
        $owned=$this->pve->owned($this->instance,$vmid);
        if (!$owned) { throw Fault::localized('message.pve_instance_not_found'); }
        $status=$this->pve->request('GET',$owned['path'].'/status/current');
        if (($vmid===null || $vmid===(int)$this->instance['vmid']) && in_array($this->instance['lifecycle'],['active','suspended'],true)) {
            Traffic::sample($this->instance,$this->pve,$status);
        }
        $expected=in_array($command,['shutdown','stop'],true) ? 'stopped' : 'running';
        $completed=empty($this->ctx['ops'][$key]['upid']) || !empty($this->ctx['done'][$key]);
        if ($completed && !in_array($command,['reboot','reset'],true) && ($status['status'] ?? '')===$expected) {
            $this->rememberPower($key,$vmid,$status['status']); return;
        }
        if (in_array($command,['reboot','reset'],true) && $status['status']!=='running'
            && empty($this->ctx['ops'][$key])) { throw Fault::localized('message.only_running_instances_can_be_restarted'); }
        $worker=$reinstall || in_array($this->task['action'],Jobs::POWER_ACTIONS,true)
            ? [($this->instance['kind']==='lxc' ? 'vz' : 'qm').$command,$owned['vmid'],$owned['node']] : null;
        $this->op($key,'POST',$owned['path'].'/status/'.$command,$command==='shutdown' ? ['timeout'=>30] : [],$worker);
        $actual=$this->pve->request('GET',$owned['path'].'/status/current');
        if (($actual['status'] ?? '')!==$expected) {
            $this->ctx['power_deadline'][$key] ??= time()+15;
            $expired=time()>=$this->ctx['power_deadline'][$key];
            if ($expired && $key==='initial-start' && ($actual['status'] ?? '')==='stopped') {
                unset($this->ctx['done'][$key],$this->ctx['ops'][$key]);
            }
            $this->save();
            if ($expired) { throw Fault::localized('message.the_pve_power_task_ended_but_the'); }
            throw new Pending(Lang::text('message.waiting_for_the_power_state_to_sync'));
        }
        $this->rememberPower($key,$vmid,$expected);
    }
    private function rememberPower(string $key,?int $vmid,string $state): void
    {
        if ($vmid===null || $vmid===(int)$this->instance['vmid']) {
            $this->mutate(['power_state'=>$state,'power_updated_at'=>Util::now()]);
        }
        $this->ctx['done'][$key]=true;
        $this->ctx['power_confirmed'][$key]=true;
        unset($this->ctx['power_deadline'][$key]);
        $this->save();
    }
    private function create(int $vmid,string $node,array $spec,array $image,string $prefix,?string $password=null): void
    {
        if ($image['kind']!==$spec['kind'] || $this->instance['kind']!==$spec['kind']) { throw Fault::localized('message.the_instance_type_does_not_match_the'); }
        $key=$prefix.'create';
        if (empty($this->ctx['done'][$key]) && empty($this->ctx['ops'][$key])) {
            $initial=$prefix==='initial-';
            $existing=$initial ? $this->pve->resource($vmid) : $this->pve->locate($vmid);
            if ($existing) {
                if ($initial && $existing['node']!==$node) {
                    $config=$this->pve->request('GET','/nodes/'.rawurlencode($existing['node']).'/'.$existing['type'].'/'.$vmid.'/config');
                    if (str_contains((string)($config['description'] ?? ''),Pve::marker($this->instance))) {
                        throw Fault::localized('message.this_services_pve_instance_is_on_another',409);
                    }
                    throw VmidConflict::localized('message.vmid_in_use',409,['vmid'=>$vmid]);
                }
                try { $owned=$this->pve->owned($this->instance,$vmid); }
                catch (IdentityMismatch $e) {
                    if ($initial) { throw VmidConflict::localized('message.vmid_in_use',409,['vmid'=>$vmid]); }
                    throw $e;
                }
                if (!$owned) { throw UnknownPveRequest::localized('message.review_required_the_candidate_instance_changed_during',409); }
                if (!empty($owned['config']['lock'])) { throw new Pending(Lang::text('message.waiting_for_the_creation_lock_to_clear')); }
                $this->ctx['done'][$key]=true; $this->save();
            } elseif ($initial) {
                if (empty($this->ctx['initial_allocation'])) {
                    throw UnknownPveRequest::localized('message.review_required_the_previous_provisioning_task_did',409);
                }
                $this->pve->request('GET','/cluster/nextid',['vmid'=>$vmid]);
            }
        }
        $params=Catalog::params((int)$this->instance['service_id']);
        $password ??= $params['password'];
        if ($password==='') { throw Fault::localized('message.the_service_login_password_has_not_been'); }
        if ($spec['kind']==='lxc') {
            $this->op($key,'POST','/nodes/'.rawurlencode($node).'/lxc',
                $this->pve->createData($this->instance,$spec,$image,$vmid,$password),['vzcreate',$vmid,$node]);
        } else {
            $source=$this->pve->locate((int)$image['source']);
            if (!$source || empty($source['template']) || $source['type']!=='qemu') { throw Fault::localized('message.the_kvm_template_is_missing_or_is'); }
            $sourceConfig=$this->pve->request('GET','/nodes/'.rawurlencode($source['node']).'/qemu/'.$image['source'].'/config');
            if (str_starts_with((string)($sourceConfig['ostype'] ?? ''),'win') || !array_filter($sourceConfig,fn($v)=>is_string($v)&&str_contains($v,'cloudinit'))) { throw Fault::localized('message.kvm_requires_a_linux_template_with_a'); }
            $sourceDisk=Pve::systemDisk($sourceConfig);
            if (count(Disks::slots($sourceConfig,'qemu'))!==1) { throw Fault::localized('message.keep_only_the_system_disk_in_the'); }
            if ($spec['disk_gib']<$sourceDisk['gib']) { throw Fault::localized('message.the_plans_system_disk_is_smaller_than'); }
            $clone=['newid'=>$vmid,'name'=>$this->instance['hostname'],'full'=>1,'pool'=>$this->pve->settings['pve_pool'],
                'storage'=>$spec['system_storage'] ?? $this->pve->settings['storage'],'description'=>Pve::marker($this->instance)];
            if ($source['node']!==$node) { $clone['target']=$node; }
            $this->op($key,'POST','/nodes/'.rawurlencode($source['node']).'/qemu/'.$image['source'].'/clone',$clone,
                ['qmclone',(int)$image['source'],$source['node']]);
            $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.could_not_find_a_cloned_instance_belonging',409);
            if (!empty($owned['config']['lock'])) { throw new Pending(Lang::text('message.waiting_for_the_clone_configuration_lock_to')); }
            $path=$owned['path']; $config=$owned['config'];
            $set=['cores'=>$spec['cores'],'memory'=>$spec['memory_mib'],'cpulimit'=>$spec['cpu_limit'],'onboot'=>0,
                'description'=>Pve::marker($this->instance),'ciuser'=>'root','cipassword'=>$password,'agent'=>'enabled=1'];
            $network=$this->pve->network($this->instance,$spec);
            $oldNets=array_filter(array_keys($config),fn($k)=>preg_match('/^(net|ipconfig)\d+$/',$k) && !isset($network[$k]));
            if ($oldNets) { $set['delete']=implode(',',$oldNets); }
            $set=array_merge($set,$network);
            $this->op($prefix.'configure','PUT',$path.'/config',$set);
            $disk=Pve::systemDisk($config);
            if ($spec['disk_gib']>$disk['gib'] || !empty($this->ctx['ops'][$prefix.'resize']['upid'])) {
                $this->op($prefix.'resize','PUT',$path.'/resize',['disk'=>$disk['slot'],'size'=>Util::json($spec['disk_gib']).'G']);
            }
        }
        $this->captureDisks($vmid,$spec,$prefix);
    }

    private function createInitial(array $spec,array $image): void
    {
        if ((int)$this->task['stage_number']<3 && empty($this->ctx['initial_allocation'])) {
            $this->ctx['initial_allocation']=true; $this->save();
        }
        for ($attempt=0;$attempt<4;$attempt++) {
            if (!$this->instance['vmid']) {
                $vmid=$this->pve->allocateId($this->ctx['conflicting_vmids'] ?? []);
                $this->ctx['initial_allocation']=true; $this->save();
                $this->mutate(['vmid'=>$vmid,'node'=>$this->ctx['node'],'lifecycle'=>'provisioning','metric_since'=>time()]);
            }
            $vmid=(int)$this->instance['vmid'];
            try {
                $this->create($vmid,$this->instance['node'],$spec,$image,'initial-');
                return;
            } catch (VmidConflict $e) {
                if (empty($this->ctx['initial_allocation']) || !empty($this->ctx['ops']['initial-create'])
                    || !empty($this->ctx['done']['initial-create']) || Util::unpack($this->instance['disks'] ?? null)) { throw $e; }
                DB::connection()->transaction(function() use($vmid) {
                    $this->ctx['conflicting_vmids'][]=$vmid;
                    $this->mutate(['vmid'=>null]);
                    $this->save();
                });
                $this->stage('message.vmid_reselect',3,5,['vmid'=>$vmid]);
            }
        }
        throw Fault::localized('message.vmids_repeatedly_conflicted_check_the_available_range',409);
    }

    private function captureDisks(int $vmid,array $spec,string $prefix): void
    {
        $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.the_instance_could_not_be_found_after');
        $inventory=Util::unpack($this->instance['disks'] ?? null);
        $system=$this->instance['kind']==='lxc' ? 'rootfs' : Pve::systemDisk($owned['config'])['slot'];
        $definitions=[['id'=>0,'storage'=>$spec['system_storage'] ?? $this->pve->settings['storage'],'size_gib'=>$spec['disk_gib'],'mountpoint'=>'/']];
        foreach ($spec['data_disks'] ?? [] as $disk) { $definitions[]=$disk; }
        foreach ($definitions as $disk) {
            $id=(int)$disk['id']; $key=$id ? 'data-'.$id : 'system-'.$vmid;
            if (isset($inventory[$key])) {
                if ((int)$inventory[$key]['vmid']!==$vmid) { throw IdentityMismatch::localized('message.the_data_disk_is_still_attached_to',409); }
                continue;
            }
            $slot=$system;
            if ($id) {
                if ($this->instance['kind']==='lxc') { $slot='mp'.($id-1); }
                else {
                    $intent=$prefix.'disk-'.$id;
                    if (!isset($this->ctx[$intent])) {
                        for ($index=1;$index<=30;$index++) { if (!isset($owned['config']['scsi'.$index])) { $this->ctx[$intent]='scsi'.$index; break; } }
                        if (!isset($this->ctx[$intent])) { throw Fault::localized('message.no_data_disk_slot_is_available'); }
                        $this->save();
                    }
                    $slot=$this->ctx[$intent];
                    $this->op($intent,'PUT',$owned['path'].'/config',[$slot=>$disk['storage'].':'.Util::json($disk['size_gib']).',serial='.Disks::serial($this->instance,$id),'digest'=>$owned['config']['digest']]);
                    $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.instance_not_found');
                }
            }
            $value=$owned['config'][$slot] ?? throw new Fault(Lang::text('message.missing_expected_disk').$slot);
            $volume=Disks::volume($value);
            $actual=Pve::sizeGib($value);
            if (!str_starts_with($volume,$disk['storage'].':') || $actual<(float)$disk['size_gib']) { throw IdentityMismatch::localized('message.the_new_disk_does_not_match_the',409); }
            $disk['size_gib']=$actual;
            $inventory[$key]=$disk+['slot'=>$slot,'volume'=>$volume,'vmid'=>$vmid,'serial'=>$id ? Disks::serial($this->instance,$id) : '', 'fs_uuid'=>''];
            $this->mutate(['disks'=>Util::json($inventory)]);
        }
        Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
    }

    private function transferDisks(int $source,int $target,string $prefix): void
    {
        foreach (Util::unpack($this->instance['disks'] ?? null) as $key=>$disk) {
            if (!$disk['id'] || (int)$disk['vmid']===$target) { continue; }
            if ((int)$disk['vmid']!==$source) { throw IdentityMismatch::localized('message.unexpected_data_disk_binding',409); }
            $from=$this->pve->owned($this->instance,$source) ?: throw Fault::localized('message.the_original_instance_does_not_exist');
            $to=$this->pve->owned($this->instance,$target) ?: throw Fault::localized('message.the_new_instance_does_not_exist');
            $slot=$disk['slot']; $op=$prefix.$disk['id'];
            if (empty($this->ctx['done'][$op]) && empty($this->ctx['ops'][$op])) {
                Disks::ownedVolumes($this->instance,$from['config'],$source);
                Disks::ownedVolumes($this->instance,$to['config'],$target);
                if (isset($to['config'][$slot])) { throw IdentityMismatch::localized('message.the_target_disk_slot_is_occupied',409); }
            }
            $lxc=$this->instance['kind']==='lxc';
            $this->op($op,'POST',$from['path'].($lxc ? '/move_volume' : '/move_disk'),[
                $lxc ? 'volume' : 'disk'=>$slot,'target-vmid'=>$target,$lxc ? 'target-volume' : 'target-disk'=>$slot,
                'digest'=>$from['config']['digest'],'target-digest'=>$to['config']['digest']],
                [$lxc ? 'move_volume' : 'qmmove',$source.'-'.$slot.'>'.$target.'-'.$slot,$from['node']]);
            $from=$this->pve->owned($this->instance,$source); $to=$this->pve->owned($this->instance,$target);
            if (isset($from['config'][$slot]) || !isset($to['config'][$slot])) { throw Fault::localized('message.the_data_disk_transfer_has_not_finished'); }
            $value=$to['config'][$slot]; $volume=Disks::volume($value);
            if (!str_starts_with($volume,$disk['storage'].':') || Pve::sizeGib($value)!=(float)$disk['size_gib']
                || (!$lxc && !str_contains($value,',serial='.$disk['serial']))) { throw IdentityMismatch::localized('message.the_transferred_data_disk_identity_does_not',409); }
            $inventory=Util::unpack($this->instance['disks']);
            $inventory[$key]=array_merge($disk,['volume'=>$volume,'vmid'=>$target]);
            $opposite=($prefix==='transfer-' ? 'rollback-transfer-' : 'transfer-').$disk['id'];
            DB::connection()->transaction(function() use($inventory,$opposite) {
                $this->mutate(['disks'=>Util::json($inventory)]);
                unset($this->ctx['done'][$opposite],$this->ctx['ops'][$opposite]); $this->save();
            });
        }
    }

    private function initDisks(int $vmid,string $mode,string $prefix): void
    {
        if ($this->instance['kind']!=='qemu') { return; }
        $inventory=Util::unpack($this->instance['disks'] ?? null);
        foreach ($inventory as $key=>$disk) {
            if (!$disk['id'] || (int)$disk['vmid']!==$vmid) { continue; }
            $op=$prefix.$disk['id'];
            if (!empty($this->ctx['done'][$op])) { continue; }
            $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.instance_not_found');
            Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
            $this->ctx[$op.'-since'] ??= time(); $this->save();
            if (time()-$this->ctx[$op.'-since']>300) { throw Fault::localized('message.guest_agent_or_disk_initialization_timed_out'); }
            if (empty($this->ctx[$op.'-pid'])) {
                try { $this->pve->request('POST',$owned['path'].'/agent/ping'); }
                catch (Fault $e) {
                    if (str_contains($e->getMessage(),'HTTP 403')) { throw $e; }
                    throw new Pending(Lang::text('message.waiting_for_guest_agent'));
                }
                $script=file_get_contents(__DIR__.'/../bin/guest-disks.sh');
                $result=$this->pve->request('POST',$owned['path'].'/agent/exec',['command'=>['/bin/sh','-s','--',
                    $disk['serial'],Util::json($disk['size_gib']),$disk['mountpoint'],$mode,$disk['fs_uuid'] ?: '-',substr($this->instance['uuid'],0,24)],'input-data'=>$script]);
                $this->ctx[$op.'-pid']=(int)$result['pid']; $this->save(); throw new Pending(Lang::text('message.initializing_the_data_disk'));
            }
            try { $result=$this->pve->request('GET',$owned['path'].'/agent/exec-status',['pid'=>$this->ctx[$op.'-pid']]); }
            catch (Fault $e) { unset($this->ctx[$op.'-pid']); $this->save(); throw $e; }
            if (empty($result['exited'])) { throw new Pending(Lang::text('message.initializing_the_data_disk')); }
            if (($result['exitcode'] ?? -1)!==0) {
                unset($this->ctx[$op.'-pid'],$this->ctx[$op.'-since']); $this->save();
                throw new Fault(Lang::text('ui.data_disk').$disk['id'].Lang::text('message.initialization_failed').mb_substr((string)($result['err-data'] ?? Lang::text('message.check_guest_agent_and_the_tools_installed')),0,400));
            }
            if (!preg_match('/WHMPVE_DISK:([0-9a-fA-F-]{36})/',(string)($result['out-data'] ?? ''),$match)) { throw Fault::localized('message.guest_agent_returned_an_invalid_mount_result'); }
            $inventory[$key]['fs_uuid']=strtolower($match[1]);
            $this->mutate(['disks'=>Util::json($inventory)]); $this->ctx['done'][$op]=true; $this->save();
        }
    }
    private function remove(int $vmid,string $key): void
    {
        if (empty($this->ctx['done'][$key])) {
            $owned=$this->pve->owned($this->instance,$vmid);
            if ($owned) {
                Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
                $this->power('stop',$vmid,$key.'-stop');
                $worker=$this->task['action']==='reinstall'
                    ? [$this->instance['kind']==='lxc' ? 'vzdestroy' : 'qmdestroy',$vmid,$owned['node']] : null;
                $this->op($key,'DELETE',$owned['path'],['purge'=>0],$worker);
            } else { $this->ctx['done'][$key]=true; $this->save(); }
        }
        $location=$this->pve->locate($vmid);
        if ($location && $this->pve->owned($this->instance,$vmid)) { throw new Pending(Lang::text('message.waiting_for_instance_deletion')); }
        $inventory=array_filter(Util::unpack($this->instance['disks'] ?? null),fn($disk)=>(int)$disk['vmid']!==$vmid);
        $this->mutate(['disks'=>Util::json($inventory)]);
    }
    private function finishStatus(string $status,array $fields=[]): void
    {
        if (empty($this->ctx['done']['whmcs_status'])) {
            Util::moduleApi('UpdateClientProduct',['serviceid'=>$this->instance['service_id'],'status'=>$status]+$fields);
            $this->ctx['done']['whmcs_status']=true; $this->save();
        }
    }
    private function enableConfiguredOnboot(): void
    {
        if (!empty($this->pve->settings['onboot'])) {
            $owned=$this->pve->owned($this->instance) ?? throw Fault::localized('message.pve_instance_not_found');
            $this->pve->setOnboot($owned,true);
        }
    }

    private function disableSourceOnboot(int $vmid,string $key): void
    {
        $owned=$this->pve->owned($this->instance,$vmid) ?? throw Fault::localized('message.the_original_instance_does_not_exist');
        if (!isset($this->ctx[$key])) {
            $this->ctx[$key]=!empty($owned['config']['onboot']);
            $this->save();
        }
        $this->pve->setOnboot($owned,false);
    }

    private function restoreSourceOnboot(int $vmid,string $key,array $spec): void
    {
        if (!empty($this->ctx[$key]) && $this->serviceMayBoot($spec)) {
            $owned=$this->pve->owned($this->instance,$vmid) ?? throw Fault::localized('message.the_original_instance_does_not_exist');
            $this->pve->setOnboot($owned,true);
        }
    }

    private function serviceMayBoot(array $spec): bool
    {
        $service=(int)$this->instance['service_id'];
        return $this->instance['lifecycle']==='active' && DB::table('tblhosting')->where('id',$service)->value('domainstatus')==='Active'
            && Traffic::target($spec,Traffic::row($service))!=='suspended';
    }

    private function finishReplacement(int $old,array $spec): void
    {
        $this->remove($old,'remove-old');
        if (!empty($this->pve->settings['onboot']) && $this->serviceMayBoot($spec)) { $this->enableConfiguredOnboot(); }
    }

    public function execute(): void
    {
        $action=$this->task['action']; $spec=Util::unpack($this->instance['applied'] ?: $this->instance['desired']);
        if (str_starts_with($this->task['actor'],'client:')) {
            $native=DB::table('tblhosting')->where('id',$this->instance['service_id'])->first(['userid','server']);
            if (!$this->ctx && (int)($native->userid ?? 0)!==(int)substr($this->task['actor'],7)) {
                throw Fault::localized('message.the_service_owner_changed_submit_the_action',403);
            }
            if ($this->instance['vmid']) { Catalog::assertServerBinding($this->instance,(int)($native->server ?? 0)); }
        }
        if ($action==='create') {
            Jobs::requireProvisioning($this->instance);
            $this->stage('message.check_the_image_and_node_capacity',1,5);
            $image=Util::scoped('images',(int)$this->instance['image_id'],(int)$this->instance['server_id']);
            if (empty($this->ctx['node'])) { $this->ctx['node']=$this->pve->chooseNode($spec,$image); $this->save(); }
            $this->stage('message.reserve_the_vmid_and_ip_addresses',2,5);
            $this->pve->reserveIp($this->instance,$spec);
            $this->stage('message.create_the_instance_and_configure_the_system',3,5);
            $this->createInitial($spec,$image);
            Jobs::requireProvisioning($this->instance);
            if (empty($this->ctx['traffic-bound'])) { Traffic::bind($this->instance); $this->ctx['traffic-bound']=true; $this->save(); }
            $this->stage('message.start_the_instance_and_initialize_data_disks',4,5); $this->power('start',null,'initial-start');
            $this->initDisks((int)$this->instance['vmid'],'create','initial-init-');
            $this->stage('message.sync_service_and_resource_records',5,5);
            DB::connection()->transaction(function() use($spec) {
                $service=(int)$this->instance['service_id'];
                DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first(['id']);
                Jobs::requireProvisioning($this->instance,true);
                $ips=$this->pve->ips($service);
                $fields=$ips ? ['dedicatedip'=>$ips[0]['address'],
                    'assignedips'=>implode("\n",array_column(array_slice($ips,1),'address'))] : [];
                $this->enableConfiguredOnboot();
                $this->finishStatus('Active',$fields);
                $this->mutate(['lifecycle'=>'active','applied'=>Util::json($spec)]);
                DB::table('mod_whmpve_ips')->where('server_id',$this->instance['server_id'])->where('service_id',$service)
                    ->update(['state'=>'assigned','updated_at'=>Util::now()]);
                $this->ctx['welcome'] ??= ['state'=>'queued','at'=>time(),'attempts'=>0];
                $this->save();
            });
            return;
        }
        if ($action==='reinstall') { $this->reinstall($spec); return; }
        if ($action==='terminate') {
            $this->stage('message.verify_linked_instances_and_clean_up',1,2);
            $ids=[]; if ($this->instance['vmid']) { $ids[]=(int)$this->instance['vmid']; }
            foreach (DB::table('mod_whmpve_tasks')->where('service_id',$this->instance['service_id'])->get(['context']) as $task) {
                $ctx=Util::unpack($task->context); foreach (['old_vmid','new_vmid'] as $key) { if (!empty($ctx[$key])) { $ids[]=(int)$ctx[$key]; } }
            }
            foreach (array_unique($ids) as $id) {
                try { $this->remove($id,'terminate-'.$id); }
                catch (IdentityMismatch $e) {
                    if ($id===(int)$this->instance['vmid']) { throw $e; }
                    Util::log(Lang::text('message.an_older_vmid_is_in_use_by').$id,(int)$this->instance['server_id'],(int)$this->instance['service_id'],'warning');
                }
            }
            $this->stage('message.release_ip_addresses_and_sync_the_deletion',2,2);
            DB::table('mod_whmpve_ips')->where('server_id',$this->instance['server_id'])->where('service_id',$this->instance['service_id'])->update(['state'=>'free','service_id'=>null,'updated_at'=>Util::now()]);
            Addons::terminateBilling($this->instance);
            $this->mutate(['vmid'=>null,'lifecycle'=>'terminated','power_state'=>'absent','power_updated_at'=>Util::now()]);
            Purchases::releaseTerminatedHolds($this->instance);
            $this->finishStatus('Terminated'); return;
        }
        if ($action==='suspend' || $action==='unsuspend') {
            $this->stage($action==='suspend' ? 'message.stop_the_instance_and_suspend_the_service' : 'message.unsuspend_the_service_and_start_the_instance',1,1);
            if ($action==='suspend') {
                Util::moduleApi('UpdateClientProduct',['serviceid'=>$this->instance['service_id'],'suspendreason'=>'']);
                $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
                $this->op('disable-onboot','PUT',$owned['path'].'/config',['onboot'=>0]); $this->power('stop');
                $this->mutate(['lifecycle'=>'suspended']); $this->finishStatus('Suspended');
            } else {
                $this->power('start');
                $this->enableConfiguredOnboot();
                $this->mutate(['lifecycle'=>'active']); $this->finishStatus('Active');
            }
            return;
        }
        if ($action==='change_package') {
            $this->stage('message.verify_the_requested_specifications',1,3);
            $purchased=Catalog::spec($this->payload['spec'],(int)$this->instance['server_id']);
            $baseline=Util::unpack($this->instance['desired']);
            foreach (['ipv4_count','ipv6_count','ip_pool_ids'] as $key) {
                if ($purchased[$key]!==$baseline[$key]) { throw Fault::localized('message.a_plan_change_cannot_change_ip_assignments'); }
            }
            $target=InstanceConfig::afterPackage($purchased,$baseline,$spec);
            if ($target['kind']!==$spec['kind'] || $target['disk_gib']<$spec['disk_gib']) { throw Fault::localized('message.the_instance_type_cannot_be_changed_and'); }
            if ($target['system_storage']!==($spec['system_storage'] ?? $this->pve->settings['storage'])) { throw Fault::localized('message.a_plan_upgrade_cannot_change_the_system'); }
            $original=array_column($spec['data_disks'] ?? [],null,'id');
            if (count($original)!==count($target['data_disks'])) { throw Fault::localized('message.a_plan_upgrade_cannot_add_or_remove'); }
            foreach ($target['data_disks'] as &$disk) {
                $old=$original[$disk['id']] ?? throw Fault::localized('message.a_plan_upgrade_cannot_change_data_disk');
                if ($disk['storage']!==$old['storage'] || $disk['size_gib']<$old['size_gib']) { throw Fault::localized('message.a_plan_upgrade_cannot_change_data_disk_2'); }
                $disk['mountpoint']=$old['mountpoint'];
            }
            unset($disk);
            foreach ($purchased['data_disks'] as &$disk) { $disk['mountpoint']=$original[$disk['id']]['mountpoint']; }
            unset($disk);
            $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
            Disks::ownedVolumes($this->instance,$owned['config'],(int)$this->instance['vmid']);
            if ($this->instance['kind']==='qemu' && array_filter($target['data_disks'],fn($d)=>$d['size_gib']>$original[$d['id']]['size_gib'])) {
                if (($this->pve->request('GET',$owned['path'].'/status/current')['status'] ?? '')!=='running') { throw Fault::localized('message.start_the_instance_before_expanding_a_kvm'); }
                $this->pve->request('POST',$owned['path'].'/agent/ping');
            }
            $this->stage('message.update_cpu_memory_and_network_limits',2,3);
            $data=['cores'=>$target['cores'],'memory'=>$target['memory_mib'],'cpulimit'=>$target['cpu_limit']];
            if ($this->instance['kind']==='lxc') { $data['swap']=$target['swap_mib']; }
            $data=array_merge($data,Pve::rates($owned['config'],Traffic::speed($target,Traffic::row((int)$this->instance['service_id']))));
            $this->op('upgrade-config','PUT',$owned['path'].'/config',$data);
            $this->growDisks($owned['path'],$spec,$target);
            $this->stage('message.save_the_applied_specifications',3,3); $this->mutate(['desired'=>Util::json($purchased),'applied'=>Util::json($target),'plan_id'=>$this->payload['plan_id'] ?? $this->instance['plan_id']]); return;
        }
        if ($action==='addon_apply') { $this->applyAddon($spec); return; }
        if (in_array($action,['edit_config','traffic','traffic_reset'],true)) {
            try {
                if ($action==='edit_config') {
                    $this->editConfig($spec);
                    if (!empty($this->ctx['quota_edited'])) { $this->traffic($this->payload['spec'],3,3); }
                } elseif ($action==='traffic') { $this->traffic($spec); }
                else {
                    $this->stage('message.reset_traffic_usage_for_this_period',1,2);
                    $applied=Purchases::apply($this->instance,(int)$this->payload['invoice_id'],$this->pve);
                    if ($applied || !empty($this->ctx['ops']['quota-resume']) || !empty($this->ctx['ops']['quota-resume-cancelled'])
                        || !empty($this->ctx['done']['quota-resume'])) {
                        $this->traffic($spec,2,2);
                    }
                }
            } catch (UnknownPveRequest $e) {
                if (empty($this->ctx['ops']['quota-resume']) && empty($this->ctx['ops']['quota-resume-cancelled'])) { throw $e; }
                throw new Pending(Lang::text('message.wait_for_the_pve_connection_and_check'));
            }
            return;
        }
        if ($action==='backup') { $this->makeBackup($spec); return; }
        if ($action==='backup_restore') { $this->restoreBackup($spec); return; }
        if ($action==='lxc_password_reset') { $this->resetLxcPassword($spec); return; }
        if ($action==='backup_delete') {
            $this->stage('ui.delete_backup',1,1);
            $record=Backups::records($this->instance)->where('id',(int)$this->payload['backup_id'])->first();
            if ($record) { $this->deleteBackup((array)$record); }
            return;
        }
        if ($action==='password') {
            if ($this->instance['kind']==='lxc') { throw Fault::localized('message.to_change_the_password_of_a_running'); }
            $this->stage('message.reset_the_system_password',1,1);
            $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
            $password=decrypt($this->payload['encrypted_password']);
            $this->op('guest-password','POST',$owned['path'].'/agent/set-user-password',['username'=>'root','password'=>$password]);
            DB::table('tblhosting')->where('id',$this->instance['service_id'])->update(['password'=>$this->payload['encrypted_password']]); return;
        }
        $this->stage('message.perform_the_power_action_and_verify_the',1,1); $this->power($action);
    }

    private function reinstall(array $spec): void
    {
        if (isset($this->ctx['reinstall_rolled_back'])) { throw new Fault($this->ctx['reinstall_rolled_back']); }
        if (!empty($this->ctx['new_vmid']) && (int)$this->ctx['new_vmid']===(int)$this->instance['vmid']) {
            if (empty($this->ctx['traffic-bound'])) {
                Traffic::bind($this->instance);
                $this->ctx['traffic-bound']=true; $this->save();
            }
            $this->stage('message.clean_up_the_stopped_original_instance',6,6);
            $this->finishReplacement((int)$this->ctx['old_vmid'],$spec);
            return;
        }
        if (isset($this->ctx['reinstall_error'])) { $this->rollbackReinstall($spec); }

        $this->stage('message.verify_the_image_and_data_disks',1,6);
        $image=Util::scoped('images',(int)$this->payload['image_id'],(int)$this->instance['server_id']);
        if (!Catalog::imageAllowed($image,$spec)) { throw Fault::localized('message.the_target_image_is_incompatible_with_the'); }
        if (!isset($this->ctx['old_vmid'])) {
            $old=$this->pve->owned($this->instance) ?: throw Fault::localized('message.the_original_instance_does_not_exist');
            Disks::ownedVolumes($this->instance,$old['config'],(int)$this->instance['vmid']);
            if (!Util::unpack($this->instance['disks'] ?? null)) {
                $this->captureDisks((int)$this->instance['vmid'],$spec,'legacy-');
            }
            $this->ctx['node']=$this->pve->chooseNode($spec,$image,true);
            $this->ctx['new_vmid']=$this->pve->allocateId();
            $this->ctx['old_vmid']=(int)$this->instance['vmid'];
            $this->ctx['old_node']=$old['node'];
            $this->ctx['new_epoch']=time(); $this->save();
        }
        $old=(int)$this->ctx['old_vmid']; $new=(int)$this->ctx['new_vmid'];
        if ($old===$new || $old!==(int)$this->instance['vmid']) {
            throw IdentityMismatch::localized('message.the_reinstall_instance_binding_changed_verify_it',409);
        }
        $this->stage('message.prepare_the_new_system_the_original_instance',2,6);
        if (empty($this->ctx['done']['replacement-ready'])) {
            $runtime=$spec;
            $runtime['bandwidth_mbps']=Traffic::speed($spec,Traffic::row((int)$this->instance['service_id']));
            $runtime['data_disks']=[];
            $password=isset($this->payload['encrypted_password']) ? decrypt($this->payload['encrypted_password']) : null;
            $this->create($new,$this->ctx['node'],$runtime,$image,'replacement-',$password);
            $this->ctx['done']['replacement-ready']=true; $this->save();
        }
        if (empty($this->ctx['reinstall_cutover'])) {
            $previousShutdown=!empty($this->ctx['done']['old-shutdown']) || !empty($this->ctx['ops']['old-shutdown']);
            $this->ctx['reinstall_was_running']=$previousShutdown ? null : $this->reinstallPowerState($old)==='running';
            $this->ctx['reinstall_cutover']=true; $this->save();
        }
        try {
            $this->stage('message.stop_the_original_instance_and_transfer_the',3,6);
            $this->disableSourceOnboot($old,'reinstall_onboot');
            $this->power('shutdown',$old,'old-shutdown');
            if ($this->reinstallPowerState($old)!=='stopped') { throw new Pending(Lang::text('message.waiting_for_the_original_instance_to_shut')); }
            $this->transferDisks($old,$new,'transfer-');
            $this->verifyReinstallDisks($new,$spec);
            $this->stage('message.start_and_verify_the_new_system',4,6);
            $this->power('start',$new,'replacement-start');
            $this->initDisks($new,'restore','replacement-init-');
            if ($this->reinstallPowerState($new)!=='running' || $this->reinstallPowerState($old)!=='stopped') {
                throw Fault::localized('message.could_not_verify_the_power_state_of');
            }
            $this->verifyReinstallDisks($new,$spec);
        } catch (Pending $e) {
            throw $e;
        } catch (UnknownPveRequest $e) {
            $this->stage('message.review_the_pve_task_and_data_disks',3,6);
            throw $e;
        } catch (\Throwable $e) {
            $this->ctx['reinstall_error']=$e->getMessage(); $this->save();
            $this->rollbackReinstall($spec);
        }
        $this->stage('message.switch_the_service_binding',5,6);
        DB::connection()->transaction(function() use($image,$new) {
            $this->mutate(['vmid'=>$new,'node'=>$this->ctx['node'],'image_id'=>$image['id'],'lifecycle'=>'active',
                'metric_since'=>$this->ctx['new_epoch'] ?? time(),'power_state'=>'running','power_updated_at'=>Util::now()]);
            if (isset($this->payload['encrypted_password'])) {
                DB::table('tblhosting')->where('id',$this->instance['service_id'])->update(['password'=>$this->payload['encrypted_password']]);
            }
            Traffic::bind($this->instance);
            $this->ctx['traffic-bound']=true; $this->save();
        });
        $this->stage('message.clean_up_the_stopped_original_instance',6,6);
        $this->finishReplacement($old,$spec);
    }

    private function reinstallPowerState(int $vmid): string
    {
        $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.could_not_find_the_instance_to_verify',409);
        $state=$this->pve->request('GET',$owned['path'].'/status/current')['status'] ?? '';
        if (!in_array($state,['running','stopped'],true)) { throw UnknownPveRequest::localized('message.review_required_could_not_verify_the_instance',409); }
        return $state;
    }

    private function verifyReinstallDisks(int $vmid,array $spec): void
    {
        $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.could_not_find_the_instance_to_verify',409);
        $inventory=Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
        $actual=array_column($inventory,'id');
        $expected=array_merge([0],array_column($spec['data_disks'] ?? [],'id'));
        sort($actual); sort($expected);
        if ($actual!==$expected) { throw IdentityMismatch::localized('message.review_required_some_data_disks_have_not',409); }
    }

    private function rollbackReinstall(array $spec): never
    {
        $old=(int)$this->ctx['old_vmid']; $new=(int)$this->ctx['new_vmid'];
        if ($old===$new || $old!==(int)$this->instance['vmid']) { throw IdentityMismatch::localized('message.the_original_instance_binding_changed',409); }
        $error=$this->ctx['reinstall_error'];
        $wasRunning=$this->ctx['reinstall_was_running'] ?? null;
        $this->stage('message.rolling_back_the_reinstall',3,6);
        if (empty($this->ctx['reinstall_disks_returned'])) {
            $returning=(bool)array_filter(array_keys($this->ctx['ops'] ?? []),fn($key)=>str_starts_with($key,'rollback-transfer-'));
            if (!$returning) {
                foreach ([$old,$new] as $vmid) {
                    $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.review_required_could_not_verify_the_rollback',409);
                    Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
                }
            }
            $moved=array_filter(Util::unpack($this->instance['disks'] ?? null),fn($disk)=>$disk['id'] && (int)$disk['vmid']===$new);
            if (($returning || $moved) && $this->reinstallPowerState($old)!=='stopped') {
                throw Fault::localized('message.review_required_the_original_instance_is_running',409);
            }
            $this->power('stop',$new,'rollback-new-stop');
            $this->transferDisks($new,$old,'rollback-transfer-');
            $this->verifyReinstallDisks($old,$spec);
            $this->verifyReinstallDisks($new,[]);
            $this->ctx['reinstall_disks_returned']=true; $this->save();
        }
        $this->verifyReinstallDisks($old,$spec);
        if ($wasRunning && $this->serviceMayBoot($spec)) {
            $this->power('start',$old,'rollback-old-start');
        }
        $this->restoreSourceOnboot($old,'reinstall_onboot',$spec);
        $this->remove($new,'rollback-remove');
        $message=($wasRunning===null ? Lang::text('message.reinstall_failed_the_data_disks_are_back') : Lang::text('message.reinstall_failed_the_original_instance_was_restored')).$error;
        $this->ctx=['reinstall_rolled_back'=>$message]; $this->save();
        throw new Fault($message);
    }

    private function makeBackup(array $spec): void
    {
        $automatic=!empty($this->payload['automatic']);
        $task=(int)$this->task['id'];
        $vmid=(int)$this->instance['vmid'];
        if (!isset($this->ctx['backup_manifest'])) {
            if (!Backups::checkCreate($this->instance,$automatic,$this->payload['cutoff'] ?? null)) {
                $this->stage('message.automatic_backup_skipped',1,1); return;
            }
            $limit=Backups::policy($this->instance)['limit'];
            if ($automatic) {
                $this->pruneBackups($limit,$task);
                if (Backups::records($this->instance)->count()>=$limit) {
                    $old=Backups::records($this->instance)->where('automatic',1)->orderBy('id')->first();
                    if ($old) { Backups::requireRotationAccess($this->pve,$this->instance,(array)$old); }
                }
            }
            $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
            $this->prepareBackup($spec,$owned);
            $this->ctx['backup_limit']=$limit;
            $this->save();
        }
        if ((int)$this->ctx['source_vmid']!==$vmid) { throw Fault::localized('message.the_instance_changed_create_a_new_backup'); }
        $this->stage('message.create_a_pve_backup',1,2);
        $archive=$this->createPveBackup('snapshot');
        $this->stage('message.save_the_backup_record',2,2);
        DB::table('mod_whmpve_backups')->updateOrInsert(['task_id'=>$task],[
            'server_id'=>$this->instance['server_id'],'service_id'=>$this->instance['service_id'],'source_vmid'=>$vmid,
            'automatic'=>$automatic,'volume'=>$archive['volid'],'size'=>$archive['size'] ?? 0,
            'manifest'=>Util::json($this->ctx['backup_manifest']),
            'created_at'=>gmdate('Y-m-d H:i:s',(int)($archive['ctime'] ?? time())),'updated_at'=>Util::now()]);
        if ($automatic) { $this->pruneBackups($this->ctx['backup_limit'],$task); }
    }

    private function prepareBackup(array $spec,array $owned): void
    {
        $vmid=(int)$owned['vmid'];
        $disks=Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
        if (!$disks) {
            $this->captureDisks($vmid,$spec,'backup-legacy-');
            $disks=Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
        }
        foreach ($disks as $slot=>&$disk) {
            $value=$owned['config'][$slot];
            preg_match('/(?:^|,)backup=([01])(?:,|$)/',$value,$flag);
            if (($flag[1] ?? '')==='0' || ($disk['id'] && $this->instance['kind']==='lxc' && ($flag[1] ?? '')!=='1')) {
                throw Fault::localized('message.disk_backup_required',422,['disk'=>$slot]);
            }
            $disk['size_gib']=Pve::sizeGib($value);
        }
        unset($disk);
        $this->ctx['backup_storage']=$this->pve->settings['backup_storage'] ?? 'local';
        $this->ctx['backup_manifest']=['disks'=>$disks,'image_id'=>$this->instance['image_id'],
            'password'=>DB::table('tblhosting')->where('id',$this->instance['service_id'])->value('password')];
        $this->ctx['source_vmid']=$vmid;
    }

    private function createPveBackup(string $mode): array
    {
        $this->op('backup','POST','/nodes/'.rawurlencode($this->pve->settings['node']).'/vzdump',[
            'vmid'=>$this->ctx['source_vmid'],'storage'=>$this->ctx['backup_storage'],'mode'=>$mode,
            'remove'=>0,'notes-template'=>Backups::note($this->instance,(int)$this->task['id']),
        ]);
        return $this->findTaskBackup() ?? throw Fault::localized('message.could_not_find_the_pve_backup_created');
    }

    private function findTaskBackup(): ?array
    {
        $storage=$this->ctx['backup_storage'];
        $note=Backups::note($this->instance,(int)$this->task['id']);
        $rows=$this->pve->request('GET',Backups::path($this->pve,$storage),['content'=>'backup','vmid'=>$this->ctx['source_vmid']]);
        $matches=array_values(array_filter($rows,fn($row)=>($row['notes'] ?? '')===$note));
        if (!$matches) { return null; }
        if (count($matches)!==1 || !str_starts_with($matches[0]['volid'] ?? '',$storage.':')) {
            throw IdentityMismatch::localized('message.could_not_uniquely_identify_the_pve_archive',409);
        }
        return $matches[0];
    }

    private function pruneBackups(int $limit,int $task): void
    {
        while (Backups::records($this->instance)->count()>$limit) {
            $old=Backups::records($this->instance)->where('automatic',1)->where('task_id','!=',$task)->orderBy('id')->first();
            if (!$old) { break; }
            $this->deleteBackup((array)$old);
        }
    }

    private function deleteBackup(array $record): void
    {
        $this->deleteArchive($record,'delete-backup-'.$record['id']);
        Backups::records($this->instance)->where('id',$record['id'])->delete();
    }

    private function deleteArchive(array $record,string $key): void
    {
        if (empty($this->ctx['done'][$key]) && !Backups::archive($this->pve,$this->instance,$record)) {
            $this->ctx['done'][$key]=true; $this->save(); return;
        }
        $storage=explode(':',$record['volume'],2)[0];
        $this->op($key,'DELETE',Backups::path($this->pve,$storage).'/'.rawurlencode($record['volume']));
    }

    private function resetLxcPassword(array $spec): void
    {
        if (empty($this->ctx['restore_bound'])) {
            if (isset($this->ctx['password_reset_error'])) { $this->rollbackPasswordReset(); }
            try {
                if (empty($this->ctx['password_reset_pending'])) { $this->preparePasswordReset($spec); }
                $this->checkPasswordResetAccount();
                $this->stage('message.shut_down_and_create_a_temporary_backup',1,6);
                if (!isset($this->ctx['password_reset_archive'])) {
                    if ($this->ctx['reset_onboot']) { $this->setResetOnboot($this->ctx['old_vmid'],0,'reset-disable-onboot'); }
                    $this->power('shutdown',$this->ctx['old_vmid'],'restore-stop');
                    $this->checkPasswordResetSource();
                    $archive=$this->createPveBackup('stop');
                    $this->ctx['password_reset_archive']=$archive['volid']; $this->save();
                }
                $this->checkPasswordResetSource();
                $this->restoreBackup($spec,$this->passwordResetRecord($this->ctx['password_reset_archive']));
            } catch (Pending $e) {
                throw $e;
            } catch (\Throwable $e) {
                if (empty($this->ctx['password_reset_pending']) || !empty($this->ctx['restore_bound'])) { throw $e; }
                foreach ($this->ctx['ops'] ?? [] as $key=>$operation) {
                    if (empty($this->ctx['done'][$key]) && (!empty($operation['upid']) || !empty($operation['uncertain']))) { throw $e; }
                }
                $this->ctx['password_reset_error']=$e->getMessage(); $this->save();
                $this->rollbackPasswordReset();
            }
        }
        $this->stage('message.clean_up_the_temporary_archive_and_original',6,6);
        $this->clearPasswordResetArchive();
        $this->remove($this->ctx['old_vmid'],'remove-old');
        if (($this->ctx['reset_onboot'] || !empty($this->pve->settings['onboot'])) && $this->serviceMayBoot($spec)) {
            $this->setResetOnboot($this->ctx['new_vmid'],1,'reset-new-onboot');
        }
        unset($this->ctx['password_reset_pending']);
        $this->save();
    }

    private function preparePasswordReset(array $spec): void
    {
        $this->checkPasswordResetAccount();
        $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.the_original_container_does_not_exist');
        $node='/nodes/'.rawurlencode($owned['node']);
        $packages=$this->pve->request('GET',$node.'/apt/versions');
        $version=array_column($packages,'OldVersion','Package')['pve-container'] ?? '';
        if (!version_compare((string)$version,'6.1.16','>=')) {
            throw Fault::localized('message.resetting_an_lxc_password_requires_pve_container');
        }
        $this->prepareBackup($spec,$owned);
        $storage=array_column($this->pve->request('GET',$node.'/storage',['content'=>'backup','enabled'=>1]),null,'storage');
        $backup=$this->ctx['backup_storage'];
        if (empty($storage[$backup]['active']) || ($storage[$backup]['avail'] ?? 0)<=0) {
            throw Fault::localized('message.the_backup_storage_is_unavailable_or_out');
        }
        $targets=array_column($this->pve->request('GET',$node.'/storage',['content'=>'rootdir','enabled'=>1]),null,'storage');
        $needed=[];
        foreach ($this->ctx['backup_manifest']['disks'] as $disk) {
            $needed[$disk['storage']]=($needed[$disk['storage']] ?? 0)+$disk['size_gib']*1073741824;
        }
        foreach ($needed as $name=>$bytes) {
            if (empty($targets[$name]['active']) || ($targets[$name]['avail'] ?? 0)<$bytes) {
                throw Fault::localized('message.restore_storage_unavailable',422,['storage'=>$name]);
            }
        }
        $this->ctx['new_vmid']=$this->pve->allocateId();
        $this->ctx['old_vmid']=$owned['vmid'];
        $status=$this->pve->request('GET',$owned['path'].'/status/current');
        if (!empty($status['ha']['managed'])) { throw Fault::localized('message.pve_ha_manages_this_container_and_may'); }
        $this->ctx['restore_running']=($status['status'] ?? '')==='running';
        $this->ctx['reset_onboot']=!empty($owned['config']['onboot']);
        $this->ctx['password_reset_pending']=true;
        $this->save();
    }

    private function checkPasswordResetAccount(bool $lock=false): void
    {
        if ($lock) {
            $current=DB::table('mod_whmpve_instances')->where('id',$this->instance['id'])->lockForUpdate()->first();
            if (!$current || (int)$current->vmid!==(int)$this->ctx['old_vmid'] || $current->uuid!==$this->instance['uuid']
                || (int)$current->server_id!==(int)$this->instance['server_id'] || $current->lifecycle!=='active') {
                throw Fault::localized('message.the_original_container_binding_or_state_changed',409);
            }
        }
        $query=DB::table('tblhosting')->where('id',$this->instance['service_id']);
        $native=($lock ? $query->lockForUpdate() : $query)->first(['userid','domainstatus','packageid']);
        $actor=$this->task['actor'];
        if (!$native || $native->domainstatus!=='Active' || $this->instance['lifecycle']!=='active'
            || (str_starts_with($actor,'client:') && (int)substr($actor,7)!==(int)$native->userid)) {
            throw Fault::localized('message.the_service_status_or_owner_changed_submit',403);
        }
        $product=DB::table('tblproducts')->where('id',$native->packageid);
        if (($lock ? $product->lockForUpdate() : $product)->value('servertype')!=='whmpve') {
            throw Fault::localized('message.the_service_module_changed_submit_the_action',403);
        }
        if (str_starts_with($actor,'admin:') && !Http::adminServicePermissions($actor)['operate']) {
            throw Fault::localized('message.your_service_management_permissions_changed_submit_the',403);
        }
        if (str_starts_with($actor,'client:')) {
            $client=DB::table('tblclients')->where('id',$native->userid);
            $client=($lock ? $client->lockForUpdate() : $client)->first(['status']);
            $subject=$this->payload['subject'] ?? '';
            $allowed=false;
            if (preg_match('/^user:(\d+)$/D',$subject,$match)) {
                $membership=DB::table('tblusers_clients')->where('client_id',$native->userid)->where('auth_user_id',(int)$match[1]);
                $membership=($lock ? $membership->lockForUpdate() : $membership)->first();
                $allowed=$membership && Http::productAccess($membership,true);
            } elseif (preg_match('/^admin:(\d+)$/D',$subject,$match)) {
                $admin=DB::table('tbladmins')->where('id',(int)$match[1])->where('disabled',0);
                $allowed=(bool)($lock ? $admin->lockForUpdate() : $admin)->first(['id']);
            }
            if (!$client || strcasecmp((string)$client->status,'Closed')===0 || !$allowed) { throw Fault::localized('message.your_service_management_permissions_changed_submit_the',403); }
        }
    }

    private function checkPasswordResetSource(): void
    {
        if ((int)$this->instance['vmid']!==(int)$this->ctx['old_vmid']) { throw Fault::localized('message.the_original_container_binding_changed',409); }
        $old=$this->pve->owned($this->instance) ?: throw Fault::localized('message.the_original_container_does_not_exist');
        if (($this->pve->request('GET',$old['path'].'/status/current')['status'] ?? '')!=='stopped') {
            throw Fault::localized('message.the_original_container_did_not_stay_powered',409);
        }
    }

    private function setResetOnboot(int $vmid,int $enabled,string $key): void
    {
        if (!empty($this->ctx['done'][$key])) { return; }
        $owned=$this->pve->owned($this->instance,$vmid) ?: throw Fault::localized('message.container_not_found');
        $this->op($key,'PUT',$owned['path'].'/config',['onboot'=>$enabled,'digest'=>$owned['config']['digest']]);
    }

    private function passwordResetRecord(string $volume): array
    {
        return ['volume'=>$volume,'source_vmid'=>$this->ctx['source_vmid'],'task_id'=>$this->task['id'],
            'manifest'=>Util::json($this->ctx['backup_manifest'])];
    }

    private function clearPasswordResetArchive(): void
    {
        if (!empty($this->ctx['password_reset_archive_deleted'])) { return; }
        $volume=$this->ctx['password_reset_archive'] ?? ($this->findTaskBackup()['volid'] ?? null);
        if ($volume) {
            $record=$this->passwordResetRecord($volume);
            $key='delete-reset-archive';
            $this->deleteArchive($record,$key);
            if (Backups::archive($this->pve,$this->instance,$record)) {
                unset($this->ctx['ops'][$key],$this->ctx['done'][$key]); $this->save();
                throw Fault::localized('message.the_temporary_archive_is_still_in_pve');
            }
        }
        $this->ctx['password_reset_archive_deleted']=true;
        $this->save();
    }

    private function passwordResetCopy(): ?array
    {
        $new=(int)$this->ctx['new_vmid'];
        foreach ($this->pve->resources() as $resource) {
            if ((int)($resource['vmid'] ?? 0)!==$new || ($resource['node'] ?? '')!==$this->pve->settings['node']) { continue; }
            try { return $this->pve->owned($this->instance,$new); }
            catch (IdentityMismatch) { return null; }
        }
        return null;
    }

    private function rollbackPasswordReset(): void
    {
        $this->stage('message.restore_the_original_container',5,6);
        $new=(int)$this->ctx['new_vmid'];
        $replacement=null;
        if (!empty($this->ctx['done']['restore-archive']) || !empty($this->ctx['password_reset_restore_submitted'])) {
            $replacement=$this->passwordResetCopy();
        }
        if ($replacement) {
            $this->power('stop',$new,'reset-rollback-stop');
            if (($this->pve->request('GET',$replacement['path'].'/status/current')['status'] ?? '')!=='stopped') {
                unset($this->ctx['done']['reset-rollback-stop']); $this->save();
                throw new Pending(Lang::text('message.waiting_for_the_copy_to_shut_down'));
            }
        }
        $active=DB::table('tblhosting')->where('id',$this->instance['service_id'])->value('domainstatus')==='Active';
        if ($this->ctx['restore_running'] && $active && $this->instance['lifecycle']==='active') {
            $this->power('start',$this->ctx['old_vmid'],'reset-rollback-start');
        }
        if ($this->ctx['reset_onboot'] && $active && $this->instance['lifecycle']==='active') {
            $this->setResetOnboot($this->ctx['old_vmid'],1,'reset-old-onboot');
        }
        if ($replacement) {
            $this->registerRestoredDisks($replacement,$this->ctx['backup_manifest']['disks']);
            $this->remove($new,'remove-reset-copy');
        }
        $this->clearPasswordResetArchive();
        $error=$this->ctx['password_reset_error'];
        $this->ctx=[]; $this->save();
        throw new Fault(Lang::text('message.password_reset_failed_the_original_container_was').$error);
    }

    private function restoreBackup(array $spec,?array $temporary=null): void
    {
        if (!empty($this->ctx['restore_bound'])) {
            $this->stage('message.clean_up_the_original_instance',5,5);
            $this->finishReplacement((int)$this->ctx['old_vmid'],$spec);
            return;
        }
        if (isset($this->ctx['restore_error'])) {
            $this->power('stop',$this->ctx['new_vmid'],'restore-rollback-stop');
            if ($this->ctx['restore_running'] && $this->serviceMayBoot($spec)) { $this->power('start',$this->ctx['old_vmid'],'restore-rollback-start'); }
            $this->restoreSourceOnboot((int)$this->ctx['old_vmid'],'restore_onboot',$spec);
            $error=$this->ctx['restore_error']; unset($this->ctx['restore_error']);
            foreach (['restore-stop','restore-start','restore-final-stop','restore-rollback-stop','restore-rollback-start'] as $key) {
                unset($this->ctx['ops'][$key],$this->ctx['done'][$key]);
            }
            $this->save();
            throw new Fault(Lang::text('message.could_not_start_the_restored_backup_the').$error);
        }
        if (!$temporary) { $this->stage('message.verify_the_backup_and_the_original_instance',1,5); }
        $record=$temporary ?? Backups::record($this->instance,(int)$this->payload['backup_id']);
        $manifest=Util::unpack($record['manifest']);
        Util::scoped('images',(int)$manifest['image_id'],(int)$this->instance['server_id']);
        $vmid=(int)$this->instance['vmid'];
        $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.the_original_instance_does_not_exist');
        $current=Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
        if (!$current) {
            $this->captureDisks($vmid,$spec,'restore-legacy-');
            $current=Disks::ownedVolumes($this->instance,$owned['config'],$vmid);
        }
        $lxc=$this->instance['kind']==='lxc';
        $disks=$manifest['disks'] ?? [];
        if (!$disks || array_diff_key($disks,$current) || array_diff_key($current,$disks)) {
            throw Fault::localized('message.the_original_instances_disks_do_not_match');
        }
        $sizes=[];
        foreach ($disks as $slot=>$disk) {
            if ($disk['id']!==$current[$slot]['id'] || $disk['storage']!==$current[$slot]['storage'] ||
                $disk['mountpoint']!==$current[$slot]['mountpoint']) { throw Fault::localized('message.the_original_instances_disks_do_not_match'); }
            $sizes[$slot]=max(Pve::sizeGib($owned['config'][$slot]),(float)$disk['size_gib']);
        }
        if (empty($this->ctx['done']['restore-archive']) && !Backups::archive($this->pve,$this->instance,$record)) {
            throw Fault::localized('message.the_backup_file_was_not_found_in',404);
        }
        if (!isset($this->ctx['new_vmid'])) {
            $this->ctx['old_vmid']=$vmid;
            $this->ctx['new_vmid']=$this->pve->allocateId();
            $this->ctx['restore_running']=($this->pve->request('GET',$owned['path'].'/status/current')['status'] ?? '')==='running';
            $this->save();
        }
        $new=(int)$this->ctx['new_vmid'];
        $this->stage('message.restore_a_copy_of_the_backup',2,$temporary ? 6 : 5);
        $params=['vmid'=>$new,'pool'=>$this->pve->settings['pve_pool'],'unique'=>1,'onboot'=>0];
        if ($lxc) {
            $params+=['ostemplate'=>$record['volume'],'restore'=>1,'start'=>0];
            if ($temporary) {
                $password=decrypt($this->payload['encrypted_password']);
                if (preg_match('#^\$(?:1|2[axy]?|5|6)\$[a-zA-Z0-9./]{1,16}\$[a-zA-Z0-9./]+$#D',$password)) {
                    $password=crypt($password,'$6$'.bin2hex(random_bytes(8)).'$');
                    if (!str_starts_with($password,'$6$')) { throw Fault::localized('message.could_not_hash_the_new_password'); }
                }
                $params['password']=$password;
            }
            foreach ($disks as $slot=>$disk) {
                $params[$slot]=$disk['storage'].':'.Util::json($sizes[$slot]).($disk['id'] ? ',mp='.$disk['mountpoint'].',backup=1' : '');
            }
        } else { $params['archive']=$record['volume']; }
        $this->op('restore-archive','POST','/nodes/'.rawurlencode($owned['node']).'/'.$this->instance['kind'],$params);
        $replacement=$this->pve->owned($this->instance,$new) ?: throw Fault::localized('message.the_restored_backup_copy_was_not_found');
        $this->stage('message.verify_and_configure_the_backup_copy',3,$temporary ? 6 : 5);
        $config=$replacement['config'];
        $inventory=$this->registerRestoredDisks($replacement,$disks);
        foreach ($sizes as $slot=>$size) {
            if (!$lxc && $size>Pve::sizeGib($config[$slot])) {
                $this->resizeDisk($replacement['path'],'restore-grow-'.$slot,$slot,$config,$size);
                if ($disks[$slot]['id']) { $this->ctx['restore_grow_data']=true; $this->save(); }
            }
        }
        $config=$this->pve->request('GET',$replacement['path'].'/config');
        foreach ($sizes as $slot=>$size) {
            $actual=Pve::sizeGib($config[$slot]);
            if ($actual<$size) { throw Fault::localized('message.the_disk_in_the_restored_copy_is'); }
            $inventory['restore-'.$new.'-'.$slot]['size_gib']=$actual;
        }
        $this->mutate(['disks'=>Util::json($inventory)]);
        $runtime=$spec;
        $runtime['bandwidth_mbps']=Traffic::speed($spec,Traffic::row((int)$this->instance['service_id']));
        $network=$this->pve->network($this->instance,$runtime);
        $settings=['cores'=>$spec['cores'],'memory'=>$spec['memory_mib'],'cpulimit'=>$spec['cpu_limit'],'onboot'=>0];
        if ($lxc) { $settings['swap']=$spec['swap_mib']; }
        elseif (!empty($manifest['password'])) { $settings+=['ciuser'=>'root','cipassword'=>decrypt($manifest['password'])]; }
        $this->op('restore-config','PUT',$replacement['path'].'/config',$settings+Pve::updateNetwork($config,$network,$this->instance['kind'])+['digest'=>$config['digest']]);
        $live=$this->pve->request('GET',$replacement['path'].'/config',['current'=>1]);
        if (!Pve::networkApplied($live,$network)) { throw Fault::localized('message.the_restored_copys_network_configuration_has_not'); }
        if (!$lxc) { $this->op('restore-cloudinit','PUT',$replacement['path'].'/cloudinit'); }

        $this->stage('message.switch_the_instance',4,$temporary ? 6 : 5);
        if ($temporary) {
            $this->checkPasswordResetAccount();
            $this->checkPasswordResetSource();
        } else {
            $this->disableSourceOnboot($vmid,'restore_onboot');
            $this->power('shutdown',$vmid,'restore-stop');
        }
        try {
            if ($temporary && !$this->ctx['restore_running']
                && (!empty($this->ctx['done']['restore-final-stop']) || !empty($this->ctx['ops']['restore-final-stop']))) {
                $this->power('shutdown',$new,'restore-final-stop');
            } elseif ($temporary || $this->ctx['restore_running'] || !empty($this->ctx['restore_grow_data'])) {
                $this->power('start',$new,'restore-start');
                if (!empty($this->ctx['restore_grow_data'])) { $this->initDisks($new,'grow','restored-init-'); }
                if (!$this->ctx['restore_running']) { $this->power('shutdown',$new,'restore-final-stop'); }
            }
        } catch (Pending $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($temporary) { throw $e; }
            $this->ctx['restore_error']=$e->getMessage(); $this->save();
            throw new Pending(Lang::text('message.restoring_the_original_instance'));
        }
        if ($temporary) {
            $expected=$this->ctx['restore_running'] ? 'running' : 'stopped';
            if (($this->pve->request('GET',$replacement['path'].'/status/current')['status'] ?? '')!==$expected) {
                throw Fault::localized('message.could_not_verify_the_copys_power_state');
            }
            $this->checkPasswordResetAccount();
            $this->checkPasswordResetSource();
        }
        $updated=[];
        foreach (Util::unpack($this->instance['disks']) as $disk) {
            if ((int)$disk['vmid']===$new) {
                $updated[$disk['id'] ? 'data-'.$disk['id'] : 'system-'.$new]=$disk;
            } else { $updated['retired-'.$disk['vmid'].'-'.$disk['id']]=$disk; }
        }
        DB::connection()->transaction(function() use($new,$owned,$manifest,$updated,$temporary) {
            if ($temporary) { $this->checkPasswordResetAccount(true); }
            $this->mutate(['vmid'=>$new,'node'=>$owned['node'],'image_id'=>$manifest['image_id'],'disks'=>Util::json($updated),
                'metric_since'=>time(),'power_state'=>$this->ctx['restore_running'] ? 'running' : 'stopped','power_updated_at'=>Util::now()]);
            DB::table('tblhosting')->where('id',$this->instance['service_id'])->update([
                'password'=>$temporary ? $this->payload['encrypted_password'] : ($manifest['password'] ?? ''),
            ]);
            Traffic::bind($this->instance);
            $this->ctx['restore_bound']=true; $this->save();
        });
        if ($temporary) { return; }
        $this->stage('message.clean_up_the_original_instance',5,5);
        $this->finishReplacement($vmid,$spec);
    }

    private function registerRestoredDisks(array $replacement,array $disks): array
    {
        $new=(int)$replacement['vmid'];
        $config=$replacement['config'];
        $slots=Disks::slots($config,$this->instance['kind']);
        if (array_diff_key($slots,$disks) || array_diff_key($disks,$slots)) {
            throw IdentityMismatch::localized('message.the_restored_copys_disk_inventory_does_not',409);
        }
        $inventory=Util::unpack($this->instance['disks']);
        $seen=[];
        foreach ($disks as $slot=>$disk) {
            $volume=Disks::volume($slots[$slot]);
            if (!str_starts_with($volume,$disk['storage'].':') ||
                ($this->instance['kind']==='qemu' && $disk['id'] && !str_contains($slots[$slot],',serial='.$disk['serial']))) {
                throw IdentityMismatch::localized('message.the_restored_disk_identity_does_not_match',409);
            }
            $key='restore-'.$new.'-'.$slot;
            if (isset($inventory[$key])) {
                if ($inventory[$key]['volume']!==$volume) { throw IdentityMismatch::localized('message.the_restored_copys_disk_changed',409); }
            } else {
                $storage=$disk['storage'];
                $seen[$storage] ??= array_column($this->pve->request('GET',Backups::path($this->pve,$storage),[
                    'vmid'=>$new,'content'=>$this->instance['kind']==='lxc' ? 'rootdir' : 'images',
                ]),'volid');
                if (!in_array($volume,$seen[$storage],true)) { throw IdentityMismatch::localized('message.a_disk_in_the_restored_copy_does',409); }
            }
            $inventory[$key]=array_replace($disk,['volume'=>$volume,'vmid'=>$new,'size_gib'=>Pve::sizeGib($slots[$slot])]);
        }
        Disks::ownedVolumes(array_replace($this->instance,['disks'=>Util::json($inventory)]),$config,$new);
        $this->mutate(['disks'=>Util::json($inventory)]);
        return $inventory;
    }

    private function growDisks(string $path,array $spec,array $target): void
    {
        $original=array_column($spec['data_disks'] ?? [],null,'id');
        $dataGrowth=array_filter($target['data_disks'] ?? [],fn($disk)=>$disk['size_gib']>$original[$disk['id']]['size_gib']);
        $systemGrowth=$target['disk_gib']>$spec['disk_gib'];
        if (!$systemGrowth && !$dataGrowth) { return; }
        $inventory=Util::unpack($this->instance['disks'] ?? null);
        $config=$this->pve->request('GET',$path.'/config');
        Disks::ownedVolumes($this->instance,$config,(int)$this->instance['vmid']);
        if ($systemGrowth) {
            $slot=$this->instance['kind']==='lxc' ? 'rootfs' : Pve::systemDisk($config)['slot'];
            $this->resizeDisk($path,'upgrade-disk',$slot,$config,$target['disk_gib']);
        }
        foreach ($dataGrowth as $disk) {
            $key='data-'.$disk['id']; $record=$inventory[$key] ?? throw Fault::localized('message.data_disk_record_not_found');
            $this->resizeDisk($path,'grow-'.$key,$record['slot'],$config,$disk['size_gib']);
        }
        $config=$this->pve->request('GET',$path.'/config');
        Disks::ownedVolumes($this->instance,$config,(int)$this->instance['vmid']);
        foreach ($inventory as &$disk) { $disk['size_gib']=Pve::sizeGib($config[$disk['slot']]); }
        unset($disk);
        if ($inventory) { $this->mutate(['disks'=>Util::json($inventory)]); }
        if ($dataGrowth && $this->instance['kind']==='qemu') {
            $this->initDisks((int)$this->instance['vmid'],'grow','upgrade-init-');
        }
    }

    private function resizeDisk(string $path,string $key,string $slot,array $config,int|float $size): void
    {
        if ($size>Pve::sizeGib($config[$slot]) || !empty($this->ctx['ops'][$key]['upid'])) {
            $this->op($key,'PUT',$path.'/resize',['disk'=>$slot,'size'=>Util::json($size).'G']);
        }
    }

    private function applyAddon(array $spec): void
    {
        $invoice=(int)$this->payload['invoice_id'];
        $effect=Purchases::resource($this->instance,$invoice);
        $service=(int)$this->instance['service_id'];
        if (empty($this->ctx['addon_config'])) {
            if (Jobs::unfinishedNetworkEdit($service)) { throw Fault::localized('message.complete_the_instances_network_configuration_first'); }
            $this->stage('message.verify_the_add_on',1,3);
            $old=Ipam::assigned($this->instance);
            $target=$spec;
            $addresses=$old;
            $kind=$effect['kind'];
            if (Addons::family($kind)) {
                $held=Ipam::addonHolds((int)$this->instance['server_id'],$invoice)->orderBy('id')->pluck('address')->all();
                if ($held!==$effect['addresses']) { throw Fault::localized('message.the_extra_ip_reservations_changed'); }
                $addresses=array_merge($addresses,$held);
                $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
                $this->ctx['addon_restart']=($this->pve->request('GET',$owned['path'].'/status/current')['status'] ?? '')==='running';
            } else {
                $target[$kind]+=$effect['quantity'];
                if ($kind==='cores') { $target['cpu_limit']+=$effect['quantity']; }
            }
            sort($addresses,SORT_STRING);
            $this->ctx['addon_config']=['revision'=>InstanceConfig::revision($this->instance,$old),'spec'=>$target,'addresses'=>$addresses];
            $this->save();
        }
        if (!empty($this->ctx['addon_restart']) && empty($this->ctx['edit_committed'])) {
            $this->power('shutdown',null,'addon-shutdown');
        }
        $this->editConfig($spec,$this->ctx['addon_config'],$invoice);
        if (!empty($this->ctx['addon_restart']) && DB::table('tblhosting')->where('id',$service)->value('domainstatus')==='Active'
            && Traffic::target($spec,Traffic::row($service))!=='suspended') {
            $this->power('start',null,'addon-start');
        }
    }

    private function editConfig(array $spec,?array $payload=null,int $invoice=0): void
    {
        if (!empty($this->ctx['edit_committed'])) { return; }
        $payload ??= $this->payload;
        $target=$payload['spec'];
        $addresses=$payload['addresses'];
        $service=(int)$this->instance['service_id'];
        $old=Ipam::assigned($this->instance); sort($old,SORT_STRING);
        if ($payload['revision']!==InstanceConfig::revision($this->instance,$old)) {
            throw Fault::localized('message.the_configuration_changed_reopen_the_form',409);
        }
        $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
        $path=$owned['path'];
        $kvm=$this->instance['kind']==='qemu';
        $this->stage('message.check_the_instance_configuration',1,3);
        $networkChange=$addresses!==$old || ($target['network'] ?? [])!==($spec['network'] ?? []) || Jobs::unfinishedNetworkEdit($service);
        $running=($this->pve->request('GET',$path.'/status/current')['status'] ?? '')==='running';
        if ($networkChange && $running) { throw Fault::localized('message.shut_down_the_vps_before_changing_ip'); }
        if ($kvm) {
            $original=array_column($spec['data_disks'] ?? [],'size_gib','id');
            $grow=array_filter($target['data_disks'] ?? [],fn($disk)=>$disk['size_gib']>$original[$disk['id']]);
            if ($grow) {
                if (!$running) { throw Fault::localized('message.start_the_instance_before_expanding_a_kvm'); }
                $this->pve->request('POST',$path.'/agent/ping');
            }
        }
        $this->stage('message.apply_the_instance_configuration',2,3);
        $this->growDisks($path,$spec,$target);
        $config=($this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found'))['config'];
        $hardware=['cores'=>$target['cores'],'memory'=>$target['memory_mib'],'cpulimit'=>$target['cpu_limit']];
        if (!$kvm) { $hardware['swap']=$target['swap_mib']; }
        $data=array_filter($hardware,fn($value,$key)=>!isset($config[$key]) || $config[$key]!=$value,ARRAY_FILTER_USE_BOTH);
        $runtime=$target; $runtime['bandwidth_mbps']=Traffic::speed($target,Traffic::row($service));
        $ips=[]; $network=[];
        if ($networkChange) {
            $this->ctx['network_edit']=true;
            $ips=DB::connection()->transaction(function() use($addresses,$invoice) {
                $rows=Ipam::editAddresses($this->instance,$addresses,true,$invoice);
                $this->save();
                return $rows;
            });
            $network=$this->pve->network($this->instance,$runtime,$ips);
            $data+=Pve::updateNetwork($config,$network,$this->instance['kind']);
        } elseif ($target['bandwidth_mbps']!=$spec['bandwidth_mbps']) {
            $data+=Pve::rates($config,(float)$runtime['bandwidth_mbps']);
        }
        if ($data || !empty($this->ctx['ops']['edit-config'])) {
            $this->op('edit-config','PUT',$path.'/config',$data+['digest'=>$config['digest']]);
        }
        if ($networkChange) {
            $actual=$this->pve->request('GET',$path.'/config',['current'=>1]);
            if (!Pve::networkApplied($actual,$network)) { throw Fault::localized('message.the_pve_network_configuration_has_not_taken'); }
            if ($kvm) { $this->op('cloudinit','PUT',$path.'/cloudinit'); }
            $target['ip_pool_ids']=array_values(array_unique(array_map('intval',array_column($ips,'pool_id'))));
            sort($target['ip_pool_ids'],SORT_NUMERIC);
            foreach ([4,6] as $family) {
                $target['ipv'.$family.'_count']=count(array_filter($ips,fn($ip)=>(int)$ip['family']===$family));
            }
        }
        $this->stage('message.sync_the_service_specifications',3,3);
        DB::connection()->transaction(function() use($service,$ips,$target,$networkChange,$spec,$invoice) {
            if ($invoice) { Purchases::resource($this->instance,$invoice,true); }
            if ($networkChange) {
                $ids=array_column($ips,'id');
                if ($invoice) {
                    Ipam::addonHolds((int)$this->instance['server_id'],$invoice)->whereIn('id',$ids)->update([
                        'state'=>'assigned','service_id'=>$service,'hold_invoice_id'=>null,'updated_at'=>Util::now(),
                    ]);
                }
                $leases=DB::table('mod_whmpve_ips')->where('server_id',$this->instance['server_id'])->where('service_id',$service);
                (clone $leases)->whereNotIn('id',$ids)->update(['service_id'=>null,'state'=>'free','updated_at'=>Util::now()]);
                $leases->whereIn('id',$ids)->update(['state'=>'assigned','updated_at'=>Util::now()]);
                DB::table('tblhosting')->where('id',$service)->update([
                    'dedicatedip'=>$ips[0]['address'] ?? '',
                    'assignedips'=>implode("\n",array_column(array_slice($ips,1),'address'))]);
            }
            $this->mutate(['applied'=>Util::json($target)]);
            $this->ctx['quota_edited']=($target['traffic_gb'] ?? 0)!=($spec['traffic_gb'] ?? 0);
            $this->ctx['edit_committed']=true; $this->save();
        });
    }

    private function traffic(array $spec,int $stage=1,int $total=1): void
    {
        $service=(int)$this->instance['service_id'];
        $row=Traffic::sample($this->instance,$this->pve);
        $target=Traffic::target($spec,$row);
        $owned=$this->pve->owned($this->instance) ?: throw Fault::localized('message.instance_not_found');
        $this->settleQuotaPower($owned['path'],'quota-resume','start');
        $this->settleQuotaPower($owned['path'],'quota-resume-cancelled','stop');
        $this->stage($target==='normal' ? 'message.restore_the_monthly_quota_and_network_limits' : ($target==='suspended' ? 'message.traffic_quota_exceeded_suspend_the_instance' : 'message.traffic_quota_exceeded_throttle_the_network'),$stage,$total);
        if ($target==='suspended') {
            $native=DB::table('tblhosting')->where('id',$service)->first();
            if ($native->domainstatus==='Active' || $native->suspendreason===Traffic::REASON) {
                $this->op('quota-onboot','PUT',$owned['path'].'/config',['onboot'=>0]);
                $this->power('stop',null,'quota-stop');
                DB::connection()->transaction(function() use($service) {
                    DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first(['id']);
                    $native=DB::table('tblhosting')->where('id',$service)->lockForUpdate()->first();
                    if ($native->domainstatus==='Active' || $native->suspendreason===Traffic::REASON) {
                        Util::moduleApi('UpdateClientProduct',['serviceid'=>$service,'status'=>'Suspended','suspendreason'=>Traffic::REASON]);
                        $this->mutate(['lifecycle'=>'suspended']);
                    }
                });
            } elseif (!empty($this->ctx['done']['quota-resume'])) { $this->power('stop',null,'quota-resume-cancelled'); }
        } else {
            $rates=Pve::rates($owned['config'],Traffic::speed($spec,$row));
            if ($rates) { $this->op('quota-rate','PUT',$owned['path'].'/config',$rates+['digest'=>$owned['config']['digest']]); }
            if ($row['restriction']==='suspended' || !empty($this->ctx['ops']['quota-resume']) || !empty($this->ctx['done']['quota-resume'])) {
                $this->resumeQuota($service,$owned['path'],$spec);
            }
        }
        DB::table('mod_whmpve_traffic')->where('service_id',$service)->update(['restriction'=>$target,'updated_at'=>Util::now()]);
    }

    private function overdue(int $service): bool
    {
        return DB::table('tblinvoiceitems as i')->join('tblinvoices as n','n.id','=','i.invoiceid')
            ->where('i.relid',$service)->where('i.type','Hosting')->whereIn('n.status',['Unpaid','Payment Pending'])
            ->where('n.duedate','<',date('Y-m-d'))->exists();
    }

    private function resumeQuota(int $service,string $path,array $spec): void
    {
        $native=DB::table('tblhosting')->where('id',$service)->first();
        if ($native->domainstatus==='Suspended' && $native->suspendreason===Traffic::REASON && !$this->overdue($service)) {
            $this->power('start',null,'quota-resume');
            if (($this->pve->request('GET',$path.'/status/current')['status'] ?? '')!=='running') {
                $this->ctx['quota_running_deadline'] ??= time()+15;
                $this->save();
                if (time()<$this->ctx['quota_running_deadline']) { throw new Pending(Lang::text('message.waiting_for_the_startup_state_to_sync')); }
                throw Fault::localized('message.the_pve_startup_task_ended_but_the');
            }
        }
        $started=!empty($this->ctx['done']['quota-resume']);
        $keepStopped=DB::connection()->transaction(function() use($service,$started) {
            DB::table('mod_whmpve_instances')->where('service_id',$service)->lockForUpdate()->first(['id']);
            $native=DB::table('tblhosting')->where('id',$service)->lockForUpdate()->first();
            if ($native->domainstatus!=='Suspended') { return $native->domainstatus!=='Active'; }
            if ($native->suspendreason!==Traffic::REASON) { return true; }
            if ($this->overdue($service)) {
                Util::moduleApi('UpdateClientProduct',['serviceid'=>$service,'suspendreason'=>'']);
                return true;
            }
            if (!$started) { throw Fault::localized('message.the_payment_status_changed_retry_restoring_the'); }
            Util::moduleApi('UpdateClientProduct',['serviceid'=>$service,'status'=>'Active','suspendreason'=>'']);
            $this->mutate(['lifecycle'=>'active']);
            return false;
        });
        if ($keepStopped && $started) { $this->power('stop',null,'quota-resume-cancelled'); }
        elseif ($started && $this->serviceMayBoot($spec)) { $this->enableConfiguredOnboot(); }
    }

    private function settleQuotaPower(string $path,string $key,string $action): void
    {
        $operation=$this->ctx['ops'][$key] ?? [];
        if (!empty($operation['uncertain'])) {
            $since=(int)$operation['since'];
            $type=($this->instance['kind']==='lxc' ? 'vz' : 'qm').$action;
            $node=$this->instance['node'];
            $upid=$this->pve->findWorker($type,(string)$this->instance['vmid'],$since,$node);
            if ($upid) {
                $this->ctx['ops'][$key]=['upid'=>$upid,'node'=>$node];
            } else {
                if (time()<$since+60) { throw new Pending(Lang::text('message.waiting_for_pve_to_record_the_power')); }
                $expected=$action==='start' ? 'running' : 'stopped';
                if (($this->pve->request('GET',$path.'/status/current')['status'] ?? '')===$expected) {
                    $this->ctx['done'][$key]=true;
                }
                unset($this->ctx['ops'][$key]);
            }
            $this->save();
        }
        if (!empty($this->ctx['ops'][$key]['upid'])) { $this->op($key,'POST',$path.'/status/'.$action); }
    }
}
