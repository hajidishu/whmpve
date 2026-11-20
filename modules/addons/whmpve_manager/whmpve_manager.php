<?php
declare(strict_types=1);
if (!defined('WHMCS')) { die('Direct access is not allowed'); }
require_once dirname(__DIR__,2).'/servers/whmpve/lib/bootstrap.php';

function whmpve_manager_config(): array
{
    return ['name'=>'WHMPVE','description'=>'Proxmox VE',
        'version'=>\Whmpve\Schema::VERSION,'author'=>'WHMPVE','language'=>'chinese','fields'=>[
            'storage_path'=>['FriendlyName'=>'控制台目录（可选）','Type'=>'text','Size'=>'64','Description'=>'留空自动创建。自定义时填写网站目录之外、PHP 可写的绝对路径。'],
        ]];
}
function whmpve_manager_activate(): array
{
    try { \Whmpve\Schema::install(); return ['status'=>'success','description'=>'已启用']; }
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
            'server_defaults'=>\Whmpve\ServerConfig::DEFAULT_LIMITS];
        $base=\Whmpve\Util::html($config['base']);
        echo '<link rel="stylesheet" href="'.$base.'/assets/console.css?v=' . \Whmpve\Schema::VERSION . '">';
        echo '<div class="wmp" id="whmpve-admin" data-config="'.\Whmpve\Util::html(\Whmpve\Util::json($config)).'"><div class="wmp-loading">正在加载 WHMPVE…</div></div>';
        echo '<script src="'.$base.'/assets/admin.js?v=' . \Whmpve\Schema::VERSION . '" defer></script>';
    } catch (\Throwable $e) { echo '<div class="alert alert-danger">'.\Whmpve\Util::html($e->getMessage()).'</div>'; }
}
function whmpve_manager_sidebar(array $vars): string
{
    $url=\Whmpve\Util::html($vars['modulelink']);
    return '<p><strong>WHMPVE</strong></p><p><a href="'.$url.'&view=servers">服务器</a></p><p><a href="'.$url.'&view=instances">实例管理</a></p><p><a href="'.$url.'&view=journal">日志/任务</a></p>';
}
