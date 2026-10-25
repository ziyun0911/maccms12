<?php
use think\facade\Route;

$addonPattern = ['addon' => '[a-z][a-z0-9_]*', 'controller' => '[A-Za-z][A-Za-z0-9_]*', 'action' => '[A-Za-z][A-Za-z0-9_]*'];

Route::any('addons/:addon/:controller/:action', '\app\common\addon\Dispatcher@run')
    ->pattern($addonPattern);
Route::any('addons/:addon/:controller', '\app\common\addon\Dispatcher@run')
    ->pattern($addonPattern)
    ->defaults(['action' => 'index']);
Route::any('addons/:addon', '\app\common\addon\Dispatcher@run')
    ->pattern($addonPattern)
    ->defaults(['controller' => 'index', 'action' => 'index']);
