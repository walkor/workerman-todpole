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
namespace Config;

/**
 * 存储配置
 * 这是一个GatewayWorker的配置文件，
 * 用于配置key/value存储，支持File、Memcache、Redis
 * 生产环境建议用Redis存储，即配置Store::$driver=self::DRIVER_REDIS;
 * 默认是File存储，存储在本地磁盘上
 * 
 * 作用：此存储用于存储Gateway进程的内部通讯地址，以及每个客户端client_id
 *            对应的Gateway地址。
 *            当Gateway启动后每个gateway进程会将自己的内部通讯地址放到这个存储里面
 *            当BusinessWorker进程启动后，会从这个存储中读取所有Gateway通讯地址，
 *            并与其通过socket相连，这样Gateway与BusinessWorker便可以通讯了
 * 
 * 如果有多个GatewayWorker应用时，每个应用的这个配置都应该不同，
 * 否则会导致多个应用间数据互通
 * 如果有多个GatewayWorker应用时，配置细节如下
 *     当Store::$driver=self::DRIVER_FILE时，每个应用的Store::$storePath应该不同
 *     当Store::$driver=self::DRIVER_MC/DRIVER_REDIS时，每个应用的
 *     Store::$gateway的ip或者端口应该不同
 *     
 * 注意：当使用Redis存储时，Redis服务端redis-server的timeout配置成0
 *           redis扩展git地址https://github.com/phpredis/phpredis
 *           redis扩展安装方法 pecl install redis
 *           
 * @author walkor
 */
class Store
{
    // 使用文件存储，注意使用文件存储无法支持workerman分布式部署
    const DRIVER_FILE = 1;
    // 使用memcache存储，支持workerman分布式部署
    const DRIVER_MC = 2;
    // 使用redis存储（推荐），支持workerman分布式部署
    const DRIVER_REDIS = 3;
    
     // DRIVER_FILE 或者 DRIVER_MC 或者 DRIVER_REDIS（推荐）
    public static $driver = self::DRIVER_FILE;
    
    //$driver为DRIVER_MC/DRIVER_REDIS时需要配置memcached/redis服务端ip和端口
    public static $gateway = array(
        '127.0.0.1:6379',
    );
    
    // $driver为DRIVER_FILE时要配置此项，实际配置在最下面一行
    public static $storePath = '';
}

// 默认系统临时目录下
Store::$storePath = sys_get_temp_dir().'/workerman-todpole/';