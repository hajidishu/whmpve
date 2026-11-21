<?php
declare(strict_types=1);
if (!defined('WHMCS')) { die('Direct access is not allowed'); }
require_once dirname(__DIR__,2).'/servers/whmpve/lib/bootstrap.php';

use Whmpve\Lang;

function whmpve_manager_config(): array
{
    return ['name'=>'WHMPVE','description'=>'Proxmox VE',
        'version'=>\Whmpve\Schema::VERSION,'author'=>'WHMPVE','fields'=>[
            'storage_path'=>['FriendlyName'=>\Whmpve\Lang::text('message.console_directory_optional'),'Type'=>'text','Size'=>'64','Description'=>\Whmpve\Lang::text('message.leave_blank_to_create_the_directory_automatically')],
        ]];
}
function whmpve_manager_activate(): array
{
    try { \Whmpve\Schema::install(); return ['status'=>'success','description'=>\Whmpve\Lang::text('message.enabled')]; }
    catch (\Throwable $e) { return ['status'=>'error','description'=>$e->getMessage()]; }
}
function whmpve_manager_deactivate(): array { return \Whmpve\Schema::deactivate(); }
function whmpve_manager_upgrade(array $vars): void { \Whmpve\Schema::install(); }
function whmpve_manager_output(array $vars): void
{
    try {
        \Whmpve\Http::admin();
        $view=$_GET['view'] ?? 'servers';
        if (!in_array($view,['servers','instances','journal'],true)) { $view='servers'; }
        $config=['base'=>\Whmpve\Util::base().'/modules/servers/whmpve','token'=>\Whmpve\Http::csrf('admin'),
            'view'=>$view,'server_id'=>(int)($_GET['server_id'] ?? 0),'service_id'=>$view==='servers' ? 0 : (int)($_GET['serviceid'] ?? 0),
            'server_defaults'=>\Whmpve\ServerConfig::DEFAULT_LIMITS,'i18n'=>Lang::browser('admin')];
        $base=\Whmpve\Util::html($config['base']);
        echo '<link rel="stylesheet" href="'.$base.'/assets/console.css?v=' . \Whmpve\Schema::VERSION . '">';
        echo '<div class="wmp" id="whmpve-admin" data-config="'.\Whmpve\Util::html(\Whmpve\Util::json($config)).'"><div class="wmp-loading">'.\Whmpve\Util::html(\Whmpve\Lang::text('message.loading_whmpve')).'</div></div>';
        foreach (['i18n','admin'] as $asset) {
            echo '<script src="'.$base.'/assets/'.$asset.'.js?v=' . \Whmpve\Schema::VERSION . '" defer></script>';
        }
    } catch (\Throwable $e) { echo '<div class="alert alert-danger">'.\Whmpve\Util::html($e->getMessage()).'</div>'; }
}
function whmpve_manager_sidebar(array $vars): string
{
    $url=\Whmpve\Util::html($vars['modulelink']);
    return '<p><strong>WHMPVE</strong></p><p><a href="'.$url.'&view=servers">'.\Whmpve\Util::html(\Whmpve\Lang::text('ui.servers')).'</a></p><p><a href="'.$url.'&view=instances">'.\Whmpve\Util::html(\Whmpve\Lang::text('ui.instances_2')).'</a></p><p><a href="'.$url.'&view=journal">'.\Whmpve\Util::html(\Whmpve\Lang::text('ui.tasks_logs')).'</a></p>';
}
