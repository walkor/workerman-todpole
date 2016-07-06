<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace GatewayWorker;

use GatewayWorker\Lib\Context;

use Workerman\Connection\TcpConnection;

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Autoloader;
use Workerman\Connection\AsyncTcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;

/**
 *
 * Gateway，基于Worker 开发
 * 用于转发客户端的数据给Worker处理，以及转发Worker的数据给客户端
 *
 * @author walkor<walkor@workerman.net>
 *
 */
class Gateway extends Worker
{
    /**
     * 版本
     *
     * @var string
     */
    const VERSION = '2.0.7';

    /**
     * 本机 IP
     *  单机部署默认 127.0.0.1，如果是分布式部署，需要设置成本机 IP
     *
     * @var string
     */
    public $lanIp = '127.0.0.1';

    /**
     * 本机端口
     *
     * @var string
     */
    public $lanPort = '127.0.0.1';

    /**
     * gateway 内部通讯起始端口，每个 gateway 实例应该都不同，步长1000
     *
     * @var int
     */
    public $startPort = 2000;

    /**
     * 注册服务地址,用于注册 Gateway BusinessWorker，使之能够通讯
     *
     * @var string
     */
    public $registerAddress = '127.0.0.1:1236';

    /**
     * 是否可以平滑重启，gateway 不能平滑重启，否则会导致连接断开
     *
     * @var bool
     */
    public $reloadable = false;

    /**
     * 心跳时间间隔
     *
     * @var int
     */
    public $pingInterval = 0;

    /**
     * $pingNotResponseLimit * $pingInterval 时间内，客户端未发送任何数据，断开客户端连接
     *
     * @var int
     */
    public $pingNotResponseLimit = 0;

    /**
     * 服务端向客户端发送的心跳数据
     *
     * @var string
     */
    public $pingData = '';
    
    /**
     * 秘钥
     *
     * @var string
     */
    public $secretKey = '';

    /**
     * 路由函数
     *
     * @var callback
     */
    public $router = null;

    /**
     * 协议加速
     *
     * @var bool
     */
    public $protocolAccelerate = true;

    /**
     * 保存客户端的所有 connection 对象
     *
     * @var array
     */
    protected $_clientConnections = array();

    /**
     * uid 到 connection 的映射，一对多关系
     */
    protected $_uidConnections = array();

    /**
     * group 到 connection 的映射，一对多关系
     *
     * @var array
     */
    protected $_groupConnections = array();

    /**
     * 保存所有 worker 的内部连接的 connection 对象
     *
     * @var array
     */
    protected $_workerConnections = array();

    /**
     * gateway 内部监听 worker 内部连接的 worker
     *
     * @var Worker
     */
    protected $_innerTcpWorker = null;

    /**
     * 当 worker 启动时
     *
     * @var callback
     */
    protected $_onWorkerStart = null;

    /**
     * 当有客户端连接时
     *
     * @var callback
     */
    protected $_onConnect = null;

    /**
     * 当客户端发来消息时
     *
     * @var callback
     */
    protected $_onMessage = null;

    /**
     * 当客户端连接关闭时
     *
     * @var callback
     */
    protected $_onClose = null;

    /**
     * 当 worker 停止时
     *
     * @var callback
     */
    protected $_onWorkerStop = null;

    /**
     * 进程启动时间
     *
     * @var int
     */
    protected $_startTime = 0;

    /**
     * gateway 监听的端口
     *
     * @var int
     */
    protected $_gatewayPort = 0;

    /**
     * 到注册中心的连接
     *
     * @var AsyncTcpConnection
     */
    protected $_registerConnection = null;
    
    /**
     * connectionId 记录器
     * @var int
     */
    protected static $_connectionIdRecorder = 1;

    /**
     * 用于保持长连接的心跳时间间隔
     *
     * @var int
     */
    const PERSISTENCE_CONNECTION_PING_INTERVAL = 25;

    /**
     * 构造函数
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        list(, , $this->_gatewayPort) = explode(':', $socket_name);
        $this->router = array("\\GatewayWorker\\Gateway", 'routerBind');

        $backrace                = debug_backtrace();
        $this->_autoloadRootPath = dirname($backrace[0]['file']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart  = array($this, 'onWorkerStart');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onConnect = $this->onConnect;
        $this->onConnect  = array($this, 'onClientConnect');

        // onMessage禁止用户设置回调
        $this->onMessage = array($this, 'onClientMessage');

        // 保存用户的回调，当对应的事件发生时触发
        $this->_onClose = $this->onClose;
        $this->onClose  = array($this, 'onClientClose');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStop = $this->onWorkerStop;
        $this->onWorkerStop  = array($this, 'onWorkerStop');

        // 记录进程启动的时间
        $this->_startTime = time();
        // 运行父方法
        parent::run();
    }

    /**
     * 当客户端发来数据时，转发给worker处理
     *
     * @param TcpConnection $connection
     * @param mixed         $data
     */
    public function onClientMessage($connection, $data)
    {
        $connection->pingNotResponseCount = -1;
        $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $connection, $data);
    }

    /**
     * 当客户端连接上来时，初始化一些客户端的数据
     * 包括全局唯一的client_id、初始化session等
     *
     * @param TcpConnection $connection
     */
    public function onClientConnect($connection)
    {
        $connection->id = self::generateConnectionId();
        // 保存该连接的内部通讯的数据包报头，避免每次重新初始化
        $connection->gatewayHeader = array(
            'local_ip'      => ip2long($this->lanIp),
            'local_port'    => $this->lanPort,
            'client_ip'     => ip2long($connection->getRemoteIp()),
            'client_port'   => $connection->getRemotePort(),
            'gateway_port'  => $this->_gatewayPort,
            'connection_id' => $connection->id,
            'flag'          => 0,
        );
        // 连接的 session
        $connection->session = '';
        // 该连接的心跳参数
        $connection->pingNotResponseCount = -1;
        // 保存客户端连接 connection 对象
        $this->_clientConnections[$connection->id] = $connection;

        // 如果用户有自定义 onConnect 回调，则执行
        if ($this->_onConnect) {
            call_user_func($this->_onConnect, $connection);
        }

        $this->sendToWorker(GatewayProtocol::CMD_ON_CONNECTION, $connection);
    }
    
    /**
     * 生成connection id
     * @return int
     */
    protected function generateConnectionId()
    {
        $max_unsigned_int = 4294967295;
        if (self::$_connectionIdRecorder >= $max_unsigned_int) {
            self::$_connectionIdRecorder = 1;
        }
        $id = self::$_connectionIdRecorder ++;
        return $id;
    }

    /**
     * 发送数据给 worker 进程
     *
     * @param int           $cmd
     * @param TcpConnection $connection
     * @param mixed         $body
     * @return bool
     */
    protected function sendToWorker($cmd, $connection, $body = '')
    {
        $gateway_data             = $connection->gatewayHeader;
        $gateway_data['cmd']      = $cmd;
        $gateway_data['body']     = $body;
        $gateway_data['ext_data'] = $connection->session;
        if ($this->_workerConnections) {
            // 调用路由函数，选择一个worker把请求转发给它
            /** @var TcpConnection $worker_connection */
            $worker_connection = call_user_func($this->router, $this->_workerConnections, $connection, $cmd, $body);
            if (false === $worker_connection->send($gateway_data)) {
                $msg = "SendBufferToWorker fail. May be the send buffer are overflow";
                $this->log($msg);
                return false;
            }
        } // 没有可用的 worker
        else {
            // gateway 启动后 1-2 秒内 SendBufferToWorker fail 是正常现象，因为与 worker 的连接还没建立起来，
            // 所以不记录日志，只是关闭连接
            $time_diff = 2;
            if (time() - $this->_startTime >= $time_diff) {
                $msg = 'SendBufferToWorker fail. The connections between Gateway and BusinessWorker are not ready';
                $this->log($msg);
            }
            $connection->destroy();
            return false;
        }
        return true;
    }

    /**
     * 随机路由，返回 worker connection 对象
     *
     * @param array         $worker_connections
     * @param TcpConnection $client_connection
     * @param int           $cmd
     * @param mixed         $buffer
     * @return TcpConnection
     */
    public static function routerRand($worker_connections, $client_connection, $cmd, $buffer)
    {
        return $worker_connections[array_rand($worker_connections)];
    }

    /**
     * client_id 与 worker 绑定
     *
     * @param array         $worker_connections
     * @param TcpConnection $client_connection
     * @param int           $cmd
     * @param mixed         $buffer
     * @return TcpConnection
     */
    public static function routerBind($worker_connections, $client_connection, $cmd, $buffer)
    {
        if (!isset($client_connection->businessworker_address) || !isset($worker_connections[$client_connection->businessworker_address])) {
            $client_connection->businessworker_address = array_rand($worker_connections);
        }
        return $worker_connections[$client_connection->businessworker_address];
    }

    /**
     * 当客户端关闭时
     *
     * @param TcpConnection $connection
     */
    public function onClientClose($connection)
    {
        // 尝试通知 worker，触发 Event::onClose
        $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $connection);
        unset($this->_clientConnections[$connection->id]);
        // 清理 uid 数据
        if (!empty($connection->uid)) {
            $uid = $connection->uid;
            unset($this->_uidConnections[$uid][$connection->id]);
            if (empty($this->_uidConnections[$uid])) {
                unset($this->_uidConnections[$uid]);
            }
        }
        // 清理 group 数据
        if (!empty($connection->groups)) {
            foreach ($connection->groups as $group) {
                unset($this->_groupConnections[$group][$connection->id]);
                if (empty($this->_groupConnections[$group])) {
                    unset($this->_groupConnections[$group]);
                }
            }
        }
        // 触发 onClose
        if ($this->_onClose) {
            call_user_func($this->_onClose, $connection);
        }
    }

    /**
     * 当 Gateway 启动的时候触发的回调函数
     *
     * @return void
     */
    public function onWorkerStart()
    {
        // 分配一个内部通讯端口
        $this->lanPort = $this->startPort + $this->id;

        // 如果有设置心跳，则定时执行
        if ($this->pingInterval > 0) {
            $timer_interval = $this->pingNotResponseLimit > 0 ? $this->pingInterval / 2 : $this->pingInterval;
            Timer::add($timer_interval, array($this, 'ping'));
        }

        // 如果BusinessWorker ip不是127.0.0.1，则需要加gateway到BusinessWorker的心跳
        if ($this->lanIp !== '127.0.0.1') {
            Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, array($this, 'pingBusinessWorker'));
        }

        // 如果 Register 服务器不在本地服务器，则需要保持心跳
        if (strpos($this->registerAddress, '127.0.0.1') !== 0) {
            Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, array($this, 'pingRegister'));
        }

        if (!class_exists('\Protocols\GatewayProtocol')) {
            class_alias('GatewayWorker\Protocols\GatewayProtocol', 'Protocols\GatewayProtocol');
        }

        // 初始化 gateway 内部的监听，用于监听 worker 的连接已经连接上发来的数据
        $this->_innerTcpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $this->_innerTcpWorker->listen();

        // 重新设置自动加载根目录
        Autoloader::setRootPath($this->_autoloadRootPath);

        // 设置内部监听的相关回调
        $this->_innerTcpWorker->onMessage = array($this, 'onWorkerMessage');

        $this->_innerTcpWorker->onConnect = array($this, 'onWorkerConnect');
        $this->_innerTcpWorker->onClose   = array($this, 'onWorkerClose');

        // 注册 gateway 的内部通讯地址，worker 去连这个地址，以便 gateway 与 worker 之间建立起 TCP 长连接
        $this->registerAddress();

        if ($this->_onWorkerStart) {
            call_user_func($this->_onWorkerStart, $this);
        }
    }


    /**
     * 当 worker 通过内部通讯端口连接到 gateway 时
     *
     * @param TcpConnection $connection
     */
    public function onWorkerConnect($connection)
    {
        if (TcpConnection::$defaultMaxSendBufferSize === $connection->maxSendBufferSize) {
            $connection->maxSendBufferSize = 50 * 1024 * 1024;
        }
        $connection->authorized = $this->secretKey ? false : true;
    }

    /**
     * 当 worker 发来数据时
     *
     * @param TcpConnection $connection
     * @param mixed         $data
     * @throws \Exception
     */
    public function onWorkerMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        if (empty($connection->authorized) && $cmd !== GatewayProtocol::CMD_WORKER_CONNECT && $cmd !== GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT) {
            echo "Unauthorized request from " . $connection->getRemoteIp() . ":" . $connection->getRemotePort() . "\n";
            return $connection->close();
        }
        switch ($cmd) {
            // BusinessWorker连接Gateway
            case GatewayProtocol::CMD_WORKER_CONNECT:
                $worker_info = json_decode($data['body'], true);
                if ($worker_info['secret_key'] !== $this->secretKey) {
                    echo "Gateway: Worker key does not match {$this->secretKey} !== {$this->secretKey}\n";
                    return $connection->close();
                }
                $connection->key                            = $connection->getRemoteIp() . ':' . $worker_info['worker_key'];
                $this->_workerConnections[$connection->key] = $connection;
                $connection->authorized = true;
                return;
            // GatewayClient连接Gateway
            case GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT:
                $worker_info = json_decode($data['body'], true);
                if ($worker_info['secret_key'] !== $this->secretKey) {
                    echo "Gateway: GatewayClient key does not match {$this->secretKey} !== {$this->secretKey}\n";
                    return $connection->close();
                }
                $connection->authorized = true;
                return;
            // 向某客户端发送数据，Gateway::sendToClient($client_id, $message);
            case GatewayProtocol::CMD_SEND_TO_ONE:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->send($data['body']);
                }
                return;
            // 关闭客户端连接，Gateway::closeClient($client_id);
            case GatewayProtocol::CMD_KICK:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->destroy();
                }
                return;
            // 广播, Gateway::sendToAll($message, $client_id_array)
            case GatewayProtocol::CMD_SEND_TO_ALL:
                $raw = (bool)($data['flag'] & GatewayProtocol::FLAG_NOT_CALL_ENCODE);
                $body = $data['body'];
                if (!$raw && $this->protocolAccelerate && $this->protocol) {
                    $body = $this->preEncodeForClient($body);
                    $raw = true;
                }
                $ext_data = $data['ext_data'] ? json_decode($data['ext_data'], true) : '';
                // $client_id_array 不为空时，只广播给 $client_id_array 指定的客户端
                if (isset($ext_data['connections'])) {
                    foreach ($ext_data['connections'] as $connection_id) {
                        if (isset($this->_clientConnections[$connection_id])) {
                            $this->_clientConnections[$connection_id]->send($body, $raw);
                        }
                    }
                } // $client_id_array 为空时，广播给所有在线客户端
                else {
                    $exclude_connection_id = !empty($ext_data['exclude']) ? $ext_data['exclude'] : null;
                    foreach ($this->_clientConnections as $client_connection) {
                        if (!isset($exclude_connection_id[$client_connection->id])) {
                            $client_connection->send($body, $raw);
                        }
                    }
                }
                return;
            // 重新赋值 session
            case GatewayProtocol::CMD_SET_SESSION:
                if (isset($this->_clientConnections[$data['connection_id']])) {
                    $this->_clientConnections[$data['connection_id']]->session = $data['ext_data'];
                }
                return;
            // session合并
            case GatewayProtocol::CMD_UPDATE_SESSION:
                if (!isset($this->_clientConnections[$data['connection_id']])) {
                    return;
                } else {
                    if (!$this->_clientConnections[$data['connection_id']]->session) {
                        $this->_clientConnections[$data['connection_id']]->session = $data['ext_data'];
                        return;
                    }
                    $session = Context::sessionDecode($this->_clientConnections[$data['connection_id']]->session);
                    $session_for_merge = Context::sessionDecode($data['ext_data']);
                    $session = $session_for_merge + $session;
                    $this->_clientConnections[$data['connection_id']]->session = Context::sessionEncode($session);
                }
                return;
            case GatewayProtocol::CMD_GET_SESSION_BY_CLIENT_ID:
                if (!isset($this->_clientConnections[$data['connection_id']])) {
                    $session = serialize(null);
                } else {
                    if (!$this->_clientConnections[$data['connection_id']]->session) {
                        $session = serialize(array());
                    } else {
                        $session = $this->_clientConnections[$data['connection_id']]->session;
                    }
                }
                $connection->send(pack('N', strlen($session)) . $session, true);
                return;
            // 获得客户端在线状态 Gateway::getALLClientInfo()
            case GatewayProtocol::CMD_GET_ALL_CLIENT_INFO:
                $client_info_array = array();
                foreach ($this->_clientConnections as $connection_id => $client_connection) {
                    $client_info_array[$connection_id] = $client_connection->session;
                }
                $buffer = serialize($client_info_array);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 判断某个 client_id 是否在线 Gateway::isOnline($client_id)
            case GatewayProtocol::CMD_IS_ONLINE:
                $buffer = serialize((int)isset($this->_clientConnections[$data['connection_id']]));
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 将 client_id 与 uid 绑定
            case GatewayProtocol::CMD_BIND_UID:
                $uid = $data['ext_data'];
                if (empty($uid)) {
                    echo "uid empty" . var_export($uid, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (isset($client_connection->uid)) {
                    $current_uid = $client_connection->uid;
                    unset($this->_uidConnections[$current_uid][$connection_id]);
                    if (empty($this->_uidConnections[$current_uid])) {
                        unset($this->_uidConnections[$current_uid]);
                    }
                }
                $client_connection->uid                      = $uid;
                $this->_uidConnections[$uid][$connection_id] = $client_connection;
                return;
            // client_id 与 uid 解绑 Gateway::unbindUid($client_id, $uid);
            case GatewayProtocol::CMD_UNBIND_UID:
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (isset($client_connection->uid)) {
                    $current_uid = $client_connection->uid;
                    unset($this->_uidConnections[$current_uid][$connection_id]);
                    if (empty($this->_uidConnections[$current_uid])) {
                        unset($this->_uidConnections[$current_uid]);
                    }
                    $client_connection->uid_info = '';
                    $client_connection->uid      = null;
                }
                return;
            // 发送数据给 uid Gateway::sendToUid($uid, $msg);
            case GatewayProtocol::CMD_SEND_TO_UID:
                $uid_array = json_decode($data['ext_data'], true);
                foreach ($uid_array as $uid) {
                    if (!empty($this->_uidConnections[$uid])) {
                        foreach ($this->_uidConnections[$uid] as $connection) {
                            /** @var TcpConnection $connection */
                            $connection->send($data['body']);
                        }
                    }
                }
                return;
            // 将 $client_id 加入用户组 Gateway::joinGroup($client_id, $group);
            case GatewayProtocol::CMD_JOIN_GROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "group empty" . var_export($group, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (!isset($client_connection->groups)) {
                    $client_connection->groups = array();
                }
                $client_connection->groups[$group]               = $group;
                $this->_groupConnections[$group][$connection_id] = $client_connection;
                return;
            // 将 $client_id 从某个用户组中移除 Gateway::leaveGroup($client_id, $group);
            case GatewayProtocol::CMD_LEAVE_GROUP:
                $group = $data['ext_data'];
                if (empty($group)) {
                    echo "leave group empty" . var_export($group, true);
                    return;
                }
                $connection_id = $data['connection_id'];
                if (!isset($this->_clientConnections[$connection_id])) {
                    return;
                }
                $client_connection = $this->_clientConnections[$connection_id];
                if (!isset($client_connection->groups[$group])) {
                    return;
                }
                unset($client_connection->groups[$group], $this->_groupConnections[$group][$connection_id]);
                return;
            // 向某个用户组发送消息 Gateway::sendToGroup($group, $msg);
            case GatewayProtocol::CMD_SEND_TO_GROUP:
                $raw = (bool)($data['flag'] & GatewayProtocol::FLAG_NOT_CALL_ENCODE);
                $body = $data['body'];
                if (!$raw && $this->protocolAccelerate && $this->protocol) {
                    $body = $this->preEncodeForClient($body);
                    $raw = true;
                }
                $ext_data = json_decode($data['ext_data'], true);
                $group_array = $ext_data['group'];
                $exclude_connection_id = $ext_data['exclude'];

                foreach ($group_array as $group) {
                    if (!empty($this->_groupConnections[$group])) {
                        foreach ($this->_groupConnections[$group] as $connection) {
                            if(!isset($exclude_connection_id[$connection->id]))
                            {
                                /** @var TcpConnection $connection */
                                $connection->send($body, $raw);
                            }
                        }
                    }
                }
                return;
            // 获取某用户组成员信息 Gateway::getClientInfoByGroup($group);
            case GatewayProtocol::CMD_GET_CLINET_INFO_BY_GROUP:
                $group = $data['ext_data'];
                if (!isset($this->_groupConnections[$group])) {
                    $buffer = serialize(array());
                    $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                    return;
                }
                $client_info_array = array();
                foreach ($this->_groupConnections[$group] as $connection_id => $client_connection) {
                    $client_info_array[$connection_id] = $client_connection->session;
                }
                $buffer = serialize($client_info_array);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 获取用户组成员数 Gateway::getClientCountByGroup($group);
            case GatewayProtocol::CMD_GET_CLIENT_COUNT_BY_GROUP:
                $group = $data['ext_data'];
                $count = 0;
                if ($group !== '') {
                    if (isset($this->_groupConnections[$group])) {
                        $count = count($this->_groupConnections[$group]);
                    }
                } else {
                    $count = count($this->_clientConnections);
                }
                $buffer = serialize($count);
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            // 获取与某个 uid 绑定的所有 client_id Gateway::getClientIdByUid($uid);
            case GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID:
                $uid = $data['ext_data'];
                if (empty($this->_uidConnections[$uid])) {
                    $buffer = serialize(array());
                } else {
                    $buffer = serialize(array_keys($this->_uidConnections[$uid]));
                }
                $connection->send(pack('N', strlen($buffer)) . $buffer, true);
                return;
            default :
                $err_msg = "gateway inner pack err cmd=$cmd";
                throw new \Exception($err_msg);
        }
    }

    /**
     * 当worker连接关闭时
     *
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        // $this->log("{$connection->key} CLOSE INNER_CONNECTION\n");
        if (isset($connection->key)) {
            unset($this->_workerConnections[$connection->key]);
        }
    }

    /**
     * 存储当前 Gateway 的内部通信地址
     *
     * @return bool
     */
    public function registerAddress()
    {
        $address                   = $this->lanIp . ':' . $this->lanPort;
        $this->_registerConnection = new AsyncTcpConnection("text://{$this->registerAddress}");
        $this->_registerConnection->send('{"event":"gateway_connect", "address":"' . $address . '", "secret_key":"' . $this->secretKey . '"}');
        $this->_registerConnection->onClose = array($this, 'onRegisterConnectionClose');
        $this->_registerConnection->connect();
    }

    public function onRegisterConnectionClose()
    {
        Timer::add(1, array($this, 'registerAddress'), null, false);
    }

    /**
     * 心跳逻辑
     *
     * @return void
     */
    public function ping()
    {
        $ping_data = $this->pingData ? (string)$this->pingData : null;
        $raw = false;
        if ($this->protocolAccelerate && $ping_data && $this->protocol) {
            $ping_data = $this->preEncodeForClient($ping_data);
            $raw = true;
        }
        // 遍历所有客户端连接
        foreach ($this->_clientConnections as $connection) {
            // 上次发送的心跳还没有回复次数大于限定值就断开
            if ($this->pingNotResponseLimit > 0 &&
                $connection->pingNotResponseCount >= $this->pingNotResponseLimit * 2
            ) {
                $connection->destroy();
                continue;
            }
            // $connection->pingNotResponseCount 为 -1 说明最近客户端有发来消息，则不给客户端发送心跳
            $connection->pingNotResponseCount++;
            if ($ping_data) {
                if ($connection->pingNotResponseCount === 0 ||
                    ($this->pingNotResponseLimit > 0 && $connection->pingNotResponseCount % 2 === 1)
                ) {
                    continue;
                }
                $connection->send($ping_data, $raw);
            }
        }
    }

    /**
     * 向 BusinessWorker 发送心跳数据，用于保持长连接
     *
     * @return void
     */
    public function pingBusinessWorker()
    {
        $gateway_data        = GatewayProtocol::$empty;
        $gateway_data['cmd'] = GatewayProtocol::CMD_PING;
        foreach ($this->_workerConnections as $connection) {
            $connection->send($gateway_data);
        }
    }

    /**
     * 向 Register 发送心跳，用来保持长连接
     */
    public function pingRegister()
    {
        if ($this->_registerConnection) {
            $this->_registerConnection->send('{"event":"ping"}');
        }
    }

    /**
     * @param mixed $data
     *
     * @return string
     */
    protected function preEncodeForClient($data)
    {
        foreach ($this->_clientConnections as $client_connection) {
            return call_user_func(array($client_connection->protocol, 'encode'), $data, $client_connection);
        }
    }

    /**
     * 当 gateway 关闭时触发，清理数据
     *
     * @return void
     */
    public function onWorkerStop()
    {
        // 尝试触发用户设置的回调
        if ($this->_onWorkerStop) {
            call_user_func($this->_onWorkerStop, $this);
        }
    }
}
