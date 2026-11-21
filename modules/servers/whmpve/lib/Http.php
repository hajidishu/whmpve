<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;
use WHMCS\User\Admin;

final class Http
{
    public static function csrf(string $scope): string
    {
        $key = 'whmpve_'.$scope.'_csrf';
        $secret = $_SESSION[$key] ??= bin2hex(random_bytes(32));
        return $scope==='client' ? hash_hmac('sha256',self::clientLoginKey((int)($_SESSION['uid'] ?? 0)),$secret) : $secret;
    }

    public static function rememberClientLogin(int $user): void
    {
        // Native Login as Owner does not emit UserLogin and replaces this credential.
        $_SESSION['whmpve_client_login'] = self::clientLoginKey($user);
    }

    private static function clientLoginKey(int $user): string
    {
        $token = $_SESSION['login_auth_tk'] ?? '';
        return $user && is_string($token) && $token!=='' ? hash('sha256',$user.':'.$token) : '';
    }

    public static function admin(): string
    {
        $id = (int)($_SESSION['adminid'] ?? 0);
        self::addonAdmin($id);
        return 'admin:'.$id;
    }

    private static function addonAdmin(int $id): Admin
    {
        $admin = $id ? Admin::find($id) : null;
        $roles = explode(',',(string)Util::setting('access',''));
        if (!$admin || !empty($admin->disabled) || !in_array((string)$admin->roleid,$roles,true)) {
            throw Fault::localized('message.sign_in_with_an_administrator_account_that',403);
        }
        return $admin;
    }

    public static function adminServicePermissions(string $actor): array
    {
        if (!preg_match('/^admin:(\d+)$/D',$actor,$match)) { throw Fault::localized('message.invalid_administrator_identity',403); }
        $admin=self::addonAdmin((int)$match[1]);
        $view=$admin->hasPermission('View Clients Products/Services');
        $operate=$view && $admin->hasPermission('Perform Server Operations');
        return ['view'=>$view,'operate'=>$operate,'edit'=>$operate && $admin->hasPermission('Edit Clients Products/Services')];
    }

    private static function serviceQuery(int $service)
    {
        return DB::table('tblhosting as h')->join('tblproducts as p','p.id','=','h.packageid')
            ->where('h.id',$service)->where('p.servertype','whmpve')->select('h.*');
    }

    public static function adminService(int $service,string $actor,string $ability='view',int $server=0): array
    {
        $permissions=self::adminServicePermissions($actor);
        if (empty($permissions[$ability])) { throw Fault::localized('message.the_required_whmcs_service_permissions_are_missing',403); }
        $native=self::serviceQuery($service)->first();
        if (!$native) { throw Fault::localized('message.service_not_found',404); }
        $instance=DB::table('mod_whmpve_instances')->where('service_id',$service)->first();
        if ($server && (!$instance || (int)$instance->server_id!==$server)) { throw Fault::localized('message.this_instance_does_not_belong_to_the',404); }
        if (!empty($instance->vmid)) { Catalog::assertServerBinding((array)$instance,(int)$native->server); }
        return ['client_id'=>(int)$native->userid,'permissions'=>$permissions,'instance'=>$instance ? (array)$instance : null];
    }

    public static function adminConsoleIdentity(int $service,int $server=0): array
    {
        $actor=self::admin();
        $binding=self::adminService($service,$actor,'operate',$server);
        return ['id'=>$binding['client_id'],'actor'=>$actor,'subject'=>$actor,'session'=>hash('sha256',session_id()),'scope'=>'admin'];
    }

    public static function client(bool $write = false): array
    {
        $auth = new \WHMCS\Authentication\CurrentUser();
        $client = $auth->client();
        if (!$client) { throw Fault::localized('message.sign_in_to_continue',401); }
        if (strcasecmp((string)$client->status,'Closed')===0) { throw Fault::localized('message.the_client_account_is_closed',403); }
        $admin=$auth->admin();
        $user=$auth->user();
        $login=self::clientLoginKey((int)($user->id ?? 0));
        $canManage=true;
        if ($admin && ($login==='' || !hash_equals($login,(string)($_SESSION['whmpve_client_login'] ?? '')))) {
            $subject='admin:'.$admin->id;
        } else {
            if (!$user) { throw Fault::localized('message.sign_in_again',401); }
            $membership = DB::table('tblusers_clients')->where('client_id',$client->id)->where('auth_user_id',$user->id)->first();
            if (!$membership) { throw Fault::localized('message.you_do_not_have_access_to_this',403); }
            if (!self::productAccess($membership,$write)) { throw Fault::localized('message.you_do_not_have_permission_to_manage',403); }
            $canManage=self::productAccess($membership,true);
            $subject='user:'.$user->id;
        }
        return ['id'=>(int)$client->id,'actor'=>'client:'.$client->id,'subject'=>$subject,'session'=>hash('sha256',session_id()),
            'can_manage'=>$canManage];
    }

    public static function productAccess(object $membership,bool $write): bool
    {
        return self::membershipAccess($membership,$write ? 'manageproducts' : 'products');
    }

    private static function membershipAccess(object $membership,string $permission): bool
    {
        if ($membership->owner) { return true; }
        $permissions=json_decode((string)$membership->permissions,true);
        if (!is_array($permissions)) { $permissions=explode(',',(string)$membership->permissions); }
        return in_array($permission,$permissions,true) || !empty($permissions[$permission]);
    }

    public static function purchaseAccess(array $identity,bool $lock=false): bool
    {
        if (str_starts_with($identity['subject'],'admin:')) {
            $query=Admin::query()->whereKey((int)substr($identity['subject'],6));
            $admin=($lock ? $query->lockForUpdate() : $query)->first();
            return $admin && !$admin->disabled && $admin->isAllowedToMasquerade()
                && $admin->hasPermission('View Clients Products/Services')
                && $admin->hasPermission('Create Invoice') && $admin->hasPermission('Manage Invoice');
        }
        if (!str_starts_with($identity['subject'],'user:')) { return false; }
        $query=DB::table('tblusers_clients')->where('client_id',$identity['id'])
            ->where('auth_user_id',(int)substr($identity['subject'],5));
        $membership=($lock ? $query->lockForUpdate() : $query)->first();
        return $membership && self::productAccess($membership,true) && self::membershipAccess($membership,'invoices');
    }

    public static function clientOverview(int $service,array $identity): array
    {
        $state=View::state($service,self::purchaseAccess($identity),null,$identity['can_manage']);
        if (isset($state['addons']['can_purchase'])) { $state['addons']['on_behalf']=str_starts_with($identity['subject'],'admin:'); }
        return $state;
    }

    public static function own(int $service,int $client): array
    {
        $row = self::serviceQuery($service)->where('h.userid',$client)->first();
        if (!$row) { throw Fault::localized('message.service_not_found',404); }
        $instance=DB::table('mod_whmpve_instances')->where('service_id',$service)->first(['server_id','vmid']);
        if (!empty($instance->vmid)) { Catalog::assertServerBinding((array)$instance,(int)$row->server); }
        return (array)$row;
    }

    public static function dispatch(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $scope = ($_GET['scope'] ?? '')==='admin' ? 'admin' : 'client';
        Lang::useScope($scope);
        $write = $_SERVER['REQUEST_METHOD']==='POST';
        try {
            if (!Schema::installed()) { throw Fault::localized('message.activate_the_module_first',503); }
            $identity = $scope==='admin' ? self::admin() : self::client($write);
            $input = [];
            if ($write) {
                $input = $GLOBALS['whmpve_request_payload'] ?? $_POST;
                if (!is_array($input)) { throw Fault::localized('message.invalid_request_format',400); }
                $token = $_SERVER['HTTP_X_WHMPVE_TOKEN'] ?? $input['token'] ?? '';
                if (!is_string($token) || !hash_equals(self::csrf($scope),$token)) { throw Fault::localized('message.refresh_the_page_and_try_again',403); }
                $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
                if ($origin && $origin!==preg_replace('#^(https?://[^/]+).*#','$1',Util::base())) { throw Fault::localized('message.invalid_request_origin',403); }
            } elseif ($_SERVER['REQUEST_METHOD']!=='GET') { throw Fault::localized('message.invalid_request_method',405); }
            if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
            $op = (string)($_GET['op'] ?? 'overview');
            $data = $scope==='admin' ? self::adminAction($op,$input,$write,$identity) : self::clientAction($op,$input,$write,$identity);
            echo Util::json(['ok'=>true,'data'=>$data]);
        } catch (\Throwable $e) {
            $code = $e instanceof Fault ? $e->http : 500;
            http_response_code($code);
            $message = $e instanceof Fault ? $e->getMessage() : Lang::text('message.the_request_failed_check_the_logs');
            if ($code>=500) {
                try { Util::log($e->getMessage(),(int)($_GET['server_id'] ?? 0)); } catch (\Throwable) {}
                if ($scope==='client') { $message = Lang::text('message.the_request_could_not_be_completed_contact'); }
            }
            echo Util::json(['ok'=>false,'error'=>$message]);
        }
        exit;
    }

    private static function clientAction(string $op,array $input,bool $write,array $identity): array
    {
        $service = (int)($input['service_id'] ?? $_GET['service_id'] ?? 0);
        $native = self::own($service,$identity['id']);
        if ($op==='overview' && !$write) {
            return self::clientOverview($service,$identity);
        }
        if ($op==='addon.purchase' && $write) { return Purchases::purchase($service,$identity,$input); }
        if ($op==='backups.automatic' && $write) {
            if (!is_bool($input['enabled'] ?? null)) { throw Fault::localized('message.choose_whether_to_enable_automatic_backups'); }
            return Backups::setAutomatic($service,$identity['id'],$input['enabled']);
        }
        if ($op==='metrics' && !$write) { return View::metrics($service,(string)($_GET['timeframe'] ?? 'hour')); }
        if ($op==='task' && !$write) {
            $task = Util::row('tasks',(int)($_GET['task_id'] ?? 0));
            if ((int)$task['service_id']!==$service) { throw Fault::localized('message.task_not_found',404); }
            return View::clientTask($task);
        }
        if ($op==='action' && $write) {
            $action = (string)($input['action'] ?? '');
            if (!in_array($action,['start','shutdown','stop','reboot','reset','reinstall','password','lxc_password_reset','backup','backup_restore','backup_delete'],true)) { throw Fault::localized('message.this_action_is_unavailable',403); }
            if (DB::table('mod_whmpve_tasks')->where('actor',$identity['actor'])->where('created_at','>',gmdate('Y-m-d H:i:s',time()-60))->count()>=20) {
                throw Fault::localized('message.too_many_requests_try_again_shortly',429);
            }
            $payload = self::actionPayload($action,$input);
            if ($action==='lxc_password_reset') { $payload['subject']=$identity['subject']; }
            $task=Jobs::enqueue($service,$action,(string)($input['request_id'] ?? ''),$payload,$identity['actor']);
            return View::clientTask(Jobs::kickPower($task));
        }
        if ($op==='console' && $write) {
            return Console::open($service,$identity,$input['mode'] ?? null);
        }
        if (in_array($op,['console.renew','console.close'],true) && $write) {
            return Console::renew($service,$identity,(string)($input['connection_id'] ?? ''),$op==='console.close');
        }
        throw Fault::localized('message.endpoint_not_found',404);
    }

    private static function adminAction(string $op,array $input,bool $write,string $actor): array
    {
        $server = (int)($_GET['server_id'] ?? $input['server_id'] ?? 0);
        $service = (int)($input['service_id'] ?? $_GET['service_id'] ?? 0);
        if (!$write) {
            return match ($op) {
                'bootstrap'=>View::adminState($server,$_GET,self::adminServicePermissions($actor)),
                'plans'=>['plans'=>View::plans($server)],
                'addons'=>['items'=>Addons::rows($server,(int)($_GET['plan_id'] ?? 0))],
                'inspect'=>(new Pve($server))->inspect(),
                'overview','metrics','instance_config','backups'=>self::adminServiceView($op,$service,$server,$actor),
                'task'=>self::adminTask((int)($_GET['task_id'] ?? 0),$server,$service,$actor),
                default=>throw Fault::localized('message.endpoint_not_found',404),
            };
        }
        return match ($op) {
            'server.save'=>['id'=>ServerConfig::save($input)],
            'server.delete'=>self::removeServer((int)($input['id'] ?? 0)),
            'plan.save'=>['id'=>Catalog::savePlan($input)],
            'addon.save'=>['id'=>Addons::save($input,$server)],
            'addon.delete'=>['deleted'=>Addons::delete($server,(int)($input['plan_id'] ?? 0),(int)($input['id'] ?? 0))],
            'image.save'=>['id'=>Catalog::saveImage($input)],
            'group.save'=>['id'=>Catalog::saveGroup($input)],
            'pool.save'=>Ipam::saveWithAddresses($input),
            'ip.add'=>Ipam::add($input),
            'record.delete'=>self::removeRecord($input,$server),
            'record.toggle'=>self::toggle($input,$server),
            'task.retry'=>self::retryTask((int)($input['task_id'] ?? 0),$server,$service,$actor),
            'service.action'=>self::serviceAction($input,$actor,$server),
            'instance_config.save'=>self::editInstance($input,$server,$actor),
            'console'=>Console::open($service,self::adminConsoleIdentity($service,$server),$input['mode'] ?? null),
            'console.renew','console.close'=>Console::renew($service,self::adminConsoleIdentity($service,$server),
                (string)($input['connection_id'] ?? ''),$op==='console.close'),
            default=>throw Fault::localized('message.endpoint_not_found',404),
        };
    }

    private static function editInstance(array $input,int $server,string $actor): array
    {
        $instance=self::adminService((int)($input['service_id'] ?? 0),$actor,'edit',$server)['instance'];
        if (!$instance || !$instance['vmid']) { throw Fault::localized('message.this_service_has_not_been_provisioned',409); }
        if (!$server || $server!==(int)$instance['server_id']) { throw Fault::localized('message.this_instance_does_not_belong_to_the',404); }
        $addresses=InstanceConfig::addresses($input['addresses'] ?? null);
        $old=Ipam::assigned($instance); sort($old,SORT_STRING);
        $revision=InstanceConfig::revision($instance,$old);
        if (($input['revision'] ?? '')!==$revision) { throw Fault::localized('message.the_configuration_changed_reopen_the_form',409); }
        $current=Util::unpack($instance['applied'] ?: $instance['desired']);
        $spec=InstanceConfig::edit($input,$current);
        $image=Util::scoped('images',(int)$instance['image_id'],$server);
        if ($spec['memory_mib']<(int)$image['min_memory'] || $spec['disk_gib']<(float)$image['min_disk']) {
            throw Fault::localized('message.memory_or_system_disk_size_is_below');
        }
        Ipam::editAddresses($instance,$addresses);
        if ($addresses!==$old || $spec['network']!==($current['network'] ?? []) || Jobs::unfinishedNetworkEdit((int)$instance['service_id'])) {
            $pve=new Pve($server);
            $owned=$pve->owned($instance) ?: throw Fault::localized('message.instance_not_found');
            if (($pve->request('GET',$owned['path'].'/status/current')['status'] ?? '')!=='stopped') {
                throw Fault::localized('message.shut_down_the_vps_before_changing_ip');
            }
        }
        return Jobs::enqueue((int)$instance['service_id'],'edit_config',(string)($input['request_id'] ?? ''),
            ['revision'=>$revision,'spec'=>$spec,'addresses'=>$addresses],$actor);
    }

    private static function serviceAction(array $input,string $actor,int $server): array
    {
        $service = (int)($input['service_id'] ?? 0);
        $instance=self::adminService($service,$actor,'operate',$server)['instance']
            ?? throw Fault::localized('message.instance_record_not_found',404);
        $action = (string)($input['action'] ?? '');
        if (!in_array($action,['start','shutdown','stop','reboot','reset','suspend','unsuspend','terminate','reinstall','password','lxc_password_reset','backup','backup_restore','backup_delete'],true)) { throw Fault::localized('message.invalid_action'); }
        if ($action==='terminate' && ($input['confirm_hostname'] ?? '')!==$instance['hostname']) { throw Fault::localized('message.enter_the_full_hostname_to_confirm_deletion'); }
        $payload = self::actionPayload($action,$input);
        return Jobs::kickPower(Jobs::enqueue($service,$action,(string)($input['request_id'] ?? ''),$payload,$actor));
    }

    private static function adminServiceView(string $op,int $service,int $server,string $actor): array
    {
        $binding=self::adminService($service,$actor,$op==='instance_config' ? 'edit' : 'view',$server);
        if ($op==='overview') { return View::state($service,false,$binding['permissions']); }
        $instance=$binding['instance'] ?? throw Fault::localized('message.instance_record_not_found',404);
        return match ($op) {
            'metrics'=>View::metrics($service,(string)($_GET['timeframe'] ?? 'hour')),
            'instance_config'=>View::instanceConfig($instance),
            'backups'=>Backups::summary($instance),
        };
    }

    private static function adminTask(int $id,int $server,int $service,string $actor): array
    {
        if (!self::adminServicePermissions($actor)['view']) { throw Fault::localized('message.the_required_whmcs_service_permissions_are_missing',403); }
        $task=View::task($id,$server,$service);
        $permissions=self::adminService((int)$task['service_id'],$actor,'view',(int)$task['server_id'])['permissions'];
        $task['can_retry']=$task['can_retry'] && $permissions[$task['action']==='edit_config' ? 'edit' : 'operate'];
        return $task;
    }

    private static function actionPayload(string $action,array $input): array
    {
        if ($action==='lxc_password_reset' && !in_array($input['confirm_downtime'] ?? null,[1,'1',true],true)) {
            throw Fault::localized('message.confirm_that_the_vps_will_shut_down');
        }
        return match($action) {
            'reinstall'=>['image_id'=>(int)($input['image_id'] ?? 0),'confirm_hostname'=>(string)($input['confirm_hostname'] ?? '')]+self::passwordPayload($input,true),
            'password','lxc_password_reset'=>self::passwordPayload($input,false),
            'backup_restore','backup_delete'=>['backup_id'=>(int)($input['backup_id'] ?? 0)],
            default=>[],
        };
    }

    private static function passwordPayload(array $input,bool $optional): array
    {
        $password=$input['new_password'] ?? '';
        $confirmation=$input['confirm_password'] ?? '';
        if ($optional && $password==='' && $confirmation==='') { return []; }
        if (!is_string($password) || !is_string($confirmation) || $password!==$confirmation) {
            throw Fault::localized('message.the_passwords_do_not_match');
        }
        if (mb_strlen($password)<5 || strlen($password)>72 || preg_match('/[\x00-\x1f\x7f]/',$password)) {
            throw Fault::localized('message.use_at_least_5_characters_and_at');
        }
        return ['encrypted_password'=>encrypt($password)];
    }

    private static function toggle(array $input,int $server): array
    {
        $table = $input['table'] ?? '';
        $id = (int)($input['id'] ?? 0);
        if (!in_array($table,['servers','plans','images'],true)) { throw Fault::localized('message.invalid_record_type'); }
        if ($table==='servers') {
            if ($server && $server!==$id) { throw Fault::localized('message.the_server_does_not_match',404); }
            ServerConfig::get($id);
        } else { Util::scoped($table,$id,$server); }
        DB::table('mod_whmpve_'.$table)->where('id',$id)->update(['active'=>(int)!empty($input['active']),'updated_at'=>Util::now()]);
        return ['id'=>$id];
    }

    private static function removeServer(int $id): array { ServerConfig::delete($id); return ['id'=>$id]; }
    private static function retryTask(int $id,int $server,int $service,string $actor): array
    {
        $task=self::adminTask($id,$server,$service,$actor);
        if (!$task['can_retry']) { throw Fault::localized('message.you_cannot_retry_this_task_or_it',403); }
        return Jobs::retry($id,$actor);
    }
    private static function removeRecord(array $input,int $server): array
    {
        Catalog::delete((string)($input['table'] ?? ''),(int)($input['id'] ?? 0),$server);
        return ['id'=>(int)$input['id']];
    }
}
