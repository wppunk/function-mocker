<?php
/**
 * WordPress environment bootstrap file.
 *
 * @package Test\Environments
 * @subpackage WordPress
 * @author Luca Tumedei <luca@theaveragedev.com>
 * @copyright 2019 Luca Tumedei
 *
 * @generated by function-mocker environment generation tool on 2019-04-22 10:22:45 (UTC)
 * @link https://github.com/lucatume/function-mocker
 */


require_once __DIR__ . '/src/utils.php';
require_once __DIR__ . '/src/filters.php';
require_once __DIR__ . '/WordPressEnvAutoloader.php';

spl_autoload_register([ new WordPressEnvAutoloader(__DIR__), 'autoload' ]);
