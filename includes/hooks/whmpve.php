<?php
if (!defined('WHMCS')) { die('Direct access is not allowed'); }
$whmpveHook=dirname(__DIR__,2).'/modules/addons/whmpve_manager/hooks.php';
if (is_file($whmpveHook)) { require_once $whmpveHook; }
