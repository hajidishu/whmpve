<?php
declare(strict_types=1);

if (!defined('WHMCS')) { die('Direct access is not allowed'); }
require_once __DIR__.'/lib/bootstrap.php';

use Whmpve\Lang;

function whmpve_MetaData(): array
{
    return ['DisplayName'=>'WHMPVE · Proxmox VE','APIVersion'=>'1.1','RequiresServer'=>false];
}
function whmpve_ConfigOptions(): array
{
    $servers=['0'=>\Whmpve\Lang::text('message.select_a_server')];
    $plans=['0'=>\Whmpve\Lang::text('ui.select_a_plan')];
    if (\Whmpve\Schema::installed()) {
        $db='WHMCS\\Database\\Capsule';
        foreach ($db::table('mod_whmpve_servers as m')->join('tblservers as s','s.id','=','m.id')->get(['s.id','s.name','m.active']) as $server) {
            $servers[$server->id]=$server->name.($server->active ? '' : \Whmpve\Lang::text('ui.disabled_3'));
        }
        $product=(int)($_REQUEST['id'] ?? 0);
        $server=(int)$db::table('tblproducts')->where('id',$product)->where('servertype','whmpve')->value('configoption1');
        if ($server) {
            foreach ($db::table('mod_whmpve_plans')->where('server_id',$server)->orderBy('name')->get() as $plan) {
                $kind=\Whmpve\Util::unpack($plan->spec)['kind'];
                $plans[$plan->id]=$plan->name.' · '.($kind==='lxc' ? 'LXC' : 'KVM').($plan->active ? '' : \Whmpve\Lang::text('ui.disabled_3'));
            }
        }
    }
    return ['Server'=>['FriendlyName'=>Lang::text('ui.servers'),'Type'=>'dropdown','Options'=>$servers],
        'Plan'=>['FriendlyName'=>Lang::text('ui.plan'),'Type'=>'dropdown','Options'=>$plans]];
}
function whmpve_CreateAccount(array $params): string { return \Whmpve\Jobs::module($params,'create'); }
function whmpve_SuspendAccount(array $params): string { return \Whmpve\Jobs::module($params,'suspend'); }
function whmpve_UnsuspendAccount(array $params): string { return \Whmpve\Jobs::module($params,'unsuspend'); }
function whmpve_TerminateAccount(array $params): string { return \Whmpve\Jobs::module($params,'terminate'); }
function whmpve_ChangePackage(array $params): string { return \Whmpve\Jobs::module($params,'change_package'); }
function whmpve_ChangePassword(array $params): string
{
    try { if (\Whmpve\Jobs::instance((int)$params['serviceid'])['kind']==='lxc') { return \Whmpve\Lang::text('message.open_the_client_area_select_reset_password'); } } catch (\Throwable) {}
    return \Whmpve\Jobs::module($params,'password',['encrypted_password'=>encrypt($params['password'])]);
}
function whmpve_Start(array $params): string { return \Whmpve\Jobs::module($params,'start'); }
function whmpve_Shutdown(array $params): string { return \Whmpve\Jobs::module($params,'shutdown'); }
function whmpve_Reboot(array $params): string { return \Whmpve\Jobs::module($params,'reboot'); }
function whmpve_Stop(array $params): string { return \Whmpve\Jobs::module($params,'stop'); }
function whmpve_AdminCustomButtonArray(): array { return [\Whmpve\Lang::text('ui.start')=>'Start',\Whmpve\Lang::text('ui.graceful_shutdown')=>'Shutdown',\Whmpve\Lang::text('ui.restart')=>'Reboot',\Whmpve\Lang::text('ui.force_shutdown')=>'Stop']; }
function whmpve_TestConnection(array $params): array
{
    try {
        $server=(int)($params['configoption1'] ?? $params['serverid'] ?? 0);
        (new \Whmpve\Pve($server))->inspect(); return ['success'=>true];
    } catch (\Throwable $e) { return ['success'=>false,'error'=>$e->getMessage()]; }
}
function whmpve_AdminServicesTabFields(array $params): array
{
    $service=(int)$params['serviceid'];
    try {
        $binding=\Whmpve\Http::adminService($service,\Whmpve\Http::admin());
    } catch (\Whmpve\Fault $e) {
        return $e->http===403 ? [] : [\Whmpve\Lang::text('message.vps_management')=>\Whmpve\Util::html($e->getMessage())];
    }
    try {
        $h=fn($value)=>\Whmpve\Util::html($value);
        $base=$h(\Whmpve\Util::base().'/modules/servers/whmpve');
        $state=$h(\Whmpve\Util::json(\Whmpve\View::state($service,false,$binding['permissions'])));
        $language=$h(\Whmpve\Util::json(Lang::browser('admin')));
        $token=$h(\Whmpve\Http::csrf('admin'));
        $tab=$h((string)($_GET['whmpve_tab'] ?? ''));
        $version=\Whmpve\Schema::VERSION;
        return [\Whmpve\Lang::text('message.vps_management')=>'<link rel="stylesheet" href="'.$base.'/assets/console.css?v='.$version.'">'
            .'<div id="whmpve-console" class="wmp" data-scope="admin" data-base="'.$base.'" data-token="'.$token
            .'" data-state="'.$state.'" data-i18n="'.$language.'" data-tab="'.$tab.'"><div class="wmp-loading">'.$h(Lang::text('message.loading_vps_management')).'</div></div>'
            .'<script src="'.$base.'/assets/i18n.js?v='.$version.'" defer></script>'
            .'<script src="'.$base.'/assets/client.js?v='.$version.'" defer></script>'];
    } catch (\Throwable $e) {
        \Whmpve\Util::log($e->getMessage(),0,$service);
        return [\Whmpve\Lang::text('message.vps_management')=>\Whmpve\Lang::text('message.could_not_load_vps_management_check_the')];
    }
}
function whmpve_ClientArea(array $params): array
{
    try {
        $identity=\Whmpve\Http::client(); \Whmpve\Http::own((int)$params['serviceid'],$identity['id']);
        $state=\Whmpve\Http::clientOverview((int)$params['serviceid'],$identity);
    } catch (\Throwable $e) { $state=['service_id'=>(int)$params['serviceid'],'error'=>$e->getMessage()]; }
    return ['tabOverviewReplacementTemplate'=>'templates/overview.tpl','templateVariables'=>[
        'whmpveState'=>\Whmpve\Util::json($state),'whmpveToken'=>\Whmpve\Http::csrf('client'),
        'whmpveLanguage'=>\Whmpve\Util::json(Lang::browser('client')),'whmpveLoading'=>Lang::text('ui.loading_console'),
        'whmpveBase'=>\Whmpve\Util::base().'/modules/servers/whmpve','whmpveVersion'=>\Whmpve\Schema::VERSION]];
}
