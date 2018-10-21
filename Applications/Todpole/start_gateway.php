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
use \Workerman\Worker;
use \Workerman\WebServer;
use \GatewayWorker\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;

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

// ws
// gateway 进程
$gateway = new Gateway("Websocket://0.0.0.0:8282");
// gateway名称，status方便查看
$gateway->name = 'TodpoleGatewayWs';
// gateway进程数
$gateway->count = 4;
// 本机ip，分布式部署时使用内网ip
$gateway->lanIp = '127.0.0.1';
// 内部通讯起始端口，假如$gateway->count=4，起始端口为4000
// 则一般会使用4001 4002 4003 4004 4个端口作为内部通讯端口 
$gateway->startPort = 2700;
// 心跳间隔
$gateway->pingInterval = 10;
// 心跳数据
$gateway->pingData = '{"type":"ping"}';
// 服务注册地址
$gateway->registerAddress = '127.0.0.1:1237';

// wss
// gateway 进程
$gateway_wss = new Gateway("Websocket://0.0.0.0:8283", $context);
// 设置transport开启ssl，websocket+ssl即wss
$gateway_wss->transport = 'ssl';
// gateway名称，status方便查看
$gateway_wss->name = 'TodpoleGatewayWss';
// gateway进程数
$gateway_wss->count = 4;
// 本机ip，分布式部署时使用内网ip
$gateway_wss->lanIp = '127.0.0.1';
// 内部通讯起始端口，假如$gateway_wss->count=4，起始端口为3700
// 则一般会使用3701 3702 3703 3704 4个端口作为内部通讯端口
$gateway_wss->startPort = 3700;
// 心跳间隔
$gateway_wss->pingInterval = 10;
// 心跳数据
$gateway_wss->pingData = '{"type":"ping"}';
// 服务注册地址
$gateway_wss->registerAddress = '127.0.0.1:1237';

/* 
// 当客户端连接上来时，设置连接的onWebSocketConnect，即在websocket握手时的回调
$gateway->onConnect = function($connection)
{
    $connection->onWebSocketConnect = function($connection , $http_header)
    {
        // 可以在这里判断连接来源是否合法，不合法就关掉连接
        // $_SERVER['HTTP_ORIGIN']标识来自哪个站点的页面发起的websocket链接
        if($_SERVER['HTTP_ORIGIN'] != 'http://kedou.workerman.net')
        {
            $connection->close();
        }
        // onWebSocketConnect 里面$_GET $_SERVER是可用的
        // var_dump($_GET, $_SERVER);
    };
}; 
*/

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

