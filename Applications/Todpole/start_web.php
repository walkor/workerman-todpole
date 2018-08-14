<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
use \Workerman\WebServer;
use \Workerman\Worker;

require_once __DIR__ . '/../../vendor/autoload.php';

// 已经申请了证书（pem/crt文件及key文件）放在了/etc/nginx/conf.d/ssl下
// 证书最好是申请的证书
$context = array(
    'ssl' => array(
        'local_cert'  => '/etc/nginx/conf.d/ssl/server.pem', // 也可以是crt文件
        'local_pk'    => '/etc/nginx/conf.d/ssl/server.key',
        'verify_peer' => false,
    ),
);

// HTTP
// WebServer
$web = new WebServer("http://0.0.0.0:8383");
// WebServer名称
$web->name = 'TodpoleWebserverHttp';
// WebServer数量
$web->count = 2;
// 设置站点根目录
$web->addRoot('www.your_domain.com', __DIR__ . '/Web');

// HTTPS
// WebServer
// https默认是用443端口，需要root运行，这里设置为8443端口，不需要root运行
$web_https = new WebServer("https://0.0.0.0:8443", $context);
// WebServer名称
$web_https->name = 'TodpoleWebserverHttps';
// WebServer数量
$web_https->count = 2;
// 设置transport开启ssl，变成http+SSL即https
$web_https->transport = 'ssl';
// 设置站点根目录
$web_https->addRoot('www.your_domain.com', __DIR__ . '/Web');

// 如果不是在根目录启动，则运行runAll方法
if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
