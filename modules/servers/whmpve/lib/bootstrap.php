<?php
declare(strict_types=1);

if (!defined('WHMCS')) {
    http_response_code(403);
    exit;
}
foreach (['Support', 'Upgrade', 'ServerConfig', 'Ipam', 'Disks', 'Catalog', 'InstanceConfig', 'Pve', 'Traffic', 'Addons', 'Purchases', 'Backups', 'Delivery', 'Jobs', 'Console', 'Http', 'View', 'Cron'] as $file) {
    require_once __DIR__ . '/' . $file . '.php';
}
