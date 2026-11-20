<?php
if (!defined('WHMCS')) { die('Direct access is not allowed'); }
require_once dirname(__DIR__,2).'/servers/whmpve/lib/bootstrap.php';
if (!defined('WHMPVE_HOOKS_REGISTERED')) {
    define('WHMPVE_HOOKS_REGISTERED',true);
    add_hook('AfterCronJob',1,function() {
        try { \Whmpve\Cron::run(); }
        catch (\Throwable $e) { error_log('WHMPVE Cron: '.$e->getMessage()); }
    });
    add_hook('EmailPreSend',1,function($vars) {
        return \Whmpve\Delivery::suppressNative((int)($vars['relid'] ?? 0),(string)($vars['messagename'] ?? '')) ? ['abortsend'=>true] : [];
    });
    add_hook('UserLogin',1,function($vars) {
        if (!defined('ADMINAREA') || !ADMINAREA) { \Whmpve\Http::rememberClientLogin((int)$vars['user']->id); }
    });
    add_hook('UserLogout',1,function() { unset($_SESSION['whmpve_client_login']); });
    add_hook('ProductEdit',1,function($vars) {
        if (!\Whmpve\Schema::installed()) { return; }
        try { \Whmpve\Disks::syncProduct((int)$vars['pid']); }
        catch (\Throwable $e) { \Whmpve\Util::log($e->getMessage()); }
    });
    add_hook('ShoppingCartValidateProductUpdate',1,function($vars) {
        if (!\Whmpve\Schema::installed()) { return []; }
        $item=$_SESSION['cart']['products'][(int)($vars['i'] ?? -1)] ?? null;
        if (!$item) { return []; }
        if (isset($vars['customfield']) && is_array($vars['customfield'])) { $item['customfields']=$vars['customfield']; }
        try { \Whmpve\Disks::cart($item); return []; }
        catch (\Whmpve\Fault $e) { return [$e->getMessage()]; }
    });
    add_hook('ShoppingCartValidateCheckout',1,function() {
        if (!\Whmpve\Schema::installed()) { return []; }
        $errors=[];
        foreach ($_SESSION['cart']['products'] ?? [] as $item) {
            try { \Whmpve\Disks::cart($item); }
            catch (\Whmpve\Fault $e) { $errors[]=$e->getMessage(); }
        }
        return array_values(array_unique($errors));
    });
    $freeze=function(int $order): void {
        if (!\Whmpve\Schema::installed()) { return; }
        try { \Whmpve\Catalog::freezeOrder($order); }
        catch (\Throwable $e) { \Whmpve\Util::log($e->getMessage()); }
    };
    add_hook('PendingOrder',1,function($vars) use ($freeze) { $freeze((int)$vars['orderid']); });
    add_hook('AfterShoppingCartCheckout',1,function($vars) use ($freeze) { $freeze((int)$vars['OrderID']); });
    add_hook('InvoiceCreationPreEmail',1,function($vars) {
        if (!\Whmpve\Schema::installed()) { return; }
        $ids=\WHMCS\Database\Capsule::table('tblinvoiceitems as i')->join('tblhosting as h','h.id','=','i.relid')
            ->join('tblproducts as p','p.id','=','h.packageid')->where('i.invoiceid',(int)$vars['invoiceid'])
            ->where('i.type','Hosting')->where('p.servertype','whmpve')->pluck('h.id');
        foreach ($ids as $id) {
            try { \Whmpve\Catalog::freeze(\Whmpve\Catalog::params((int)$id)); }
            catch (\Throwable $e) { \Whmpve\Util::log($e->getMessage(),0,(int)$id); }
        }
    });
    add_hook('InvoicePaidPreEmail',1,function($vars) use ($freeze) {
        $orders=\WHMCS\Database\Capsule::table('tblorders')->where('invoiceid',(int)$vars['invoiceid'])->pluck('id');
        foreach ($orders as $order) { $freeze((int)$order); }
    });
    add_hook('InvoicePaid',1,function($vars) {
        if (!\Whmpve\Schema::installed()) { return; }
        try { \Whmpve\Jobs::paidInvoice((int)$vars['invoiceid']); }
        catch (\Throwable $e) { \Whmpve\Util::log($e->getMessage()); }
    });
    add_hook('AdminAreaFooterOutput',1,function($vars) {
        if (($vars['filename'] ?? '')!=='configproducts' || !\Whmpve\Schema::installed()) { return ''; }
        try { \Whmpve\Http::admin(); } catch (\Throwable) { return ''; }
        $product=\WHMCS\Database\Capsule::table('tblproducts')->where('id',(int)($_GET['id'] ?? 0))->first();
        if ($product && $product->servertype==='whmpve' && (int)$product->configoption2) {
            try { \Whmpve\Disks::syncProduct((int)$product->id); }
            catch (\Whmpve\Fault $e) { \Whmpve\Util::log($e->getMessage()); }
        }
        $config=['base'=>\Whmpve\Util::base().'/modules/servers/whmpve','token'=>\Whmpve\Http::csrf('admin'),
            'server'=>$product && $product->servertype==='whmpve' ? (int)$product->configoption1 : 0,
            'plan'=>$product && $product->servertype==='whmpve' ? (int)$product->configoption2 : 0];
        return '<script id="whmpve-product-config" type="application/json">'.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>'
            .'<script src="'.\Whmpve\Util::html($config['base']).'/assets/product.js?v=0.4.0" defer></script>';
    });
    add_hook('ClientAreaPrimarySidebar',1,function($sidebar) {
        if (($_GET['action'] ?? '')!=='productdetails' || !\Whmpve\Schema::installed()) { return; }
        $service=(int)($_GET['id'] ?? 0);
        $kind=\WHMCS\Database\Capsule::table('mod_whmpve_instances')->where('service_id',$service)->value('kind');
        if ($kind!=='lxc') { return; }
        $walk=function($parent) use (&$walk) {
            foreach ($parent->getChildren() as $name=>$child) {
                if ($name==='Change Password') { $parent->removeChild($name); }
                else { $walk($child); }
            }
        };
        $walk($sidebar);
    });
}
