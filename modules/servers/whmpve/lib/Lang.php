<?php
declare(strict_types=1);
namespace Whmpve;

use WHMCS\Database\Capsule as DB;

final class Lang
{
    private const DEFAULT_LANGUAGE = 'chinese';
    private const DIRECTORY = __DIR__.'/../lang/';
    private static array $catalogs = [];
    private static array $languages = [];
    private static ?string $scope = null;

    public static function useScope(string $scope): void { self::$scope=$scope; }

    public static function language(?string $scope = null): string
    {
        $scope ??= self::$scope ?? (defined('ADMINAREA') && ADMINAREA ? 'admin' : 'client');
        if (isset(self::$languages[$scope])) { return self::$languages[$scope]; }
        $language = '';
        if ($scope==='admin') {
            $id=(int)($_SESSION['adminid'] ?? 0);
            if ($id && class_exists(DB::class)) {
                $language=(string)DB::table('tbladmins')->where('id',$id)->value('language');
            }
        } else {
            $language=(string)($_SESSION['Language'] ?? '');
            if ($language==='' && class_exists('Lang')) { $language=(string)\Lang::getName(); }
        }
        return self::$languages[$scope]=self::resolve($language);
    }

    public static function resolve(string $language): string
    {
        $language=strtolower(trim($language));
        return preg_match('/^[a-z][a-z0-9_-]*$/D',$language) && self::catalog($language)
            ? $language : self::DEFAULT_LANGUAGE;
    }

    private static function catalog(string $language): array
    {
        if (!isset(self::$catalogs[$language])) {
            $path=self::DIRECTORY.$language.'.json';
            $raw=is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            $decoded=$raw===false ? null : json_decode($raw,true,16);
            self::$catalogs[$language]=is_array($decoded) ? array_filter($decoded,
                fn($value,$key)=>is_string($key) && is_string($value) && trim($value)!=='',ARRAY_FILTER_USE_BOTH) : [];
        }
        return self::$catalogs[$language];
    }

    public static function text(string $key,array $values=[],?string $language=null): string
    {
        $current=self::catalog($language===null ? self::language() : self::resolve($language));
        $text=$current[$key] ?? self::catalog(self::DEFAULT_LANGUAGE)[$key] ?? $key;
        return $values ? strtr($text,array_combine(array_map(fn($name)=>'{'.$name.'}',array_keys($values)),array_values($values))) : $text;
    }

    public static function display(string $original,?array $translation): string
    {
        return $translation ? self::text($translation['key'],$translation['values']) : $original;
    }

    public static function browser(string $scope): array
    {
        $language=self::language($scope);
        $catalog=self::catalog($language)+self::catalog(self::DEFAULT_LANGUAGE);
        return ['language'=>$language,'locale'=>$catalog['_locale'] ?? null,
            'messages'=>array_filter($catalog,fn($key)=>str_starts_with($key,'ui.'),ARRAY_FILTER_USE_KEY)];
    }
}
