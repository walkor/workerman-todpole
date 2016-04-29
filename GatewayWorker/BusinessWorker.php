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

use Workerman\Connection\TcpConnection;

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;
use GatewayWorker\Protocols\GatewayProtocol;
use GatewayWorker\Lib\Context;

/**
 *
 * BusinessWorker 用于处理Gateway转发来的数据
 *
 * @author walkor<walkor@workerman.net>
 *
 */
class BusinessWorker extends Worker
{
    /**
     * 保存与 gateway 的连接 connection 对象
     *
     * @var array
     */
    public $gatewayConnections = array();

    /**
     * 注册中心地址
     *
     * @var string
     */
    public $registerAddress = '127.0.0.1:1236';

    /**
     * 事件处理类，默认是 Event 类
     *
     * @var string
     */
    public $eventHandler = 'Events';

    /**
     * 业务超时时间，可用来定位程序卡在哪里
     *
     * @var int
     */
    public $processTimeout = 30;

    /**
     * 业务超时时间，可用来定位程序卡在哪里
     *
     * @var callable
     */
    public $processTimeoutHandler = '\\Workerman\\Worker::log';
    
    /**
     * 秘钥
     * @var string
     */
    public $secretKey = '';

    /**
     * 保存用户设置的 worker 启动回调
     *
     * @var callback
     */
    protected $_onWorkerStart = null;

    /**
     * 保存用户设置的 workerReload 回调
     *
     * @var callback
     */
    protected $_onWorkerReload = null;
    
    /**
     * 保存用户设置的 workerStop 回调
     *
     * @var callback
     */
    protected $_onWorkerStop= null;

    /**
     * 到注册中心的连接
     *
     * @var AsyncTcpConnection
     */
    protected $_registerConnection = null;

    /**
     * 处于连接状态的 gateway 通讯地址
     *
     * @var array
     */
    protected $_connectingGatewayAddresses = array();

    /**
     * 所有 geteway 内部通讯地址
     *
     * @var array
     */
    protected $_gatewayAddresses = array();

    /**
     * 等待连接个 gateway 地址
     *
     * @var array
     */
    protected $_waitingConnectGatewayAddresses = array();

    /**
     * Event::onConnect 回调
     *
     * @var callback
     */
    protected $_eventOnConnect = null;

    /**
     * Event::onMessage 回调
     *
     * @var callback
     */
    protected $_eventOnMessage = null;

    /**
     * Event::onClose 回调
     *
     * @var callback
     */
    protected $_eventOnClose = null;

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
    public function __construct($socket_name = '', $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        $backrace                = debug_backtrace();
        $this->_autoloadRootPath = dirname($backrace[0]['file']);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->_onWorkerStart  = $this->onWorkerStart;
        $this->_onWorkerReload = $this->onWorkerReload;
        $this->_onWorkerStop = $this->onWorkerStop;
        $this->onWorkerStart   = array($this, 'onWorkerStart');
        $this->onWorkerReload  = array($this, 'onWorkerReload');
        parent::run();
    }

    /**
     * 当进程启动时一些初始化工作
     *
     * @return void
     */
    protected function onWorkerStart()
    {
        if (!class_exists('\Protocols\GatewayProtocol')) {
            class_alias('GatewayWorker\Protocols\GatewayProtocol', 'Protocols\GatewayProtocol');
        }
        $this->connectToRegister();
        \GatewayWorker\Lib\Gateway::setBusinessWorker($this);
        \GatewayWorker\Lib\Gateway::$secretKey = $this->secretKey;
        if ($this->_onWorkerStart) {
            call_user_func($this->_onWorkerStart, $this);
        }
        
        if (is_callable($this->eventHandler . '::onWorkerStart')) {
            call_user_func($this->eventHandler . '::onWorkerStart', $this);
        }

        if (function_exists('pcntl_signal')) {
            // 业务超时信号处理
            pcntl_signal(SIGALRM, array($this, 'timeoutHandler'), false);
        } else {
            $this->processTimeout = 0;
        }

        // 设置回调
        if (is_callable($this->eventHandler . '::onConnect')) {
            $this->_eventOnConnect = $this->eventHandler . '::onConnect';
        }

        if (is_callable($this->eventHandler . '::onMessage')) {
            $this->_eventOnMessage = $this->eventHandler . '::onMessage';
        } else {
            echo "Waring: {$this->eventHandler}::onMessage is not callable\n";
        }

        if (is_callable($this->eventHandler . '::onClose')) {
            $this->_eventOnClose = $this->eventHandler . '::onClose';
        }

        // 如果Register服务器不在本地服务器，则需要保持心跳
        if (strpos($this->registerAddress, '127.0.0.1') !== 0) {
            Timer::add(self::PERSISTENCE_CONNECTION_PING_INTERVAL, array($this, 'pingRegister'));
        }
    }

    /**
     * onWorkerReload 回调
     *
     * @param Worker $worker
     */
    protected function onWorkerReload($worker)
    {
        // 防止进程立刻退出
        $worker->reloadable = false;
        // 延迟 0.01 秒退出，避免 BusinessWorker 瞬间全部退出导致没有可用的 BusinessWorker 进程
        Timer::add(0.05, array('Workerman\Worker', 'stopAll'));
        // 执行用户定义的 onWorkerReload 回调
        if ($this->_onWorkerReload) {
            call_user_func($this->_onWorkerReload, $this);
        }
    }
    
    /**
     * 当进程关闭时一些清理工作
     *
     * @return void
     */
    protected function onWorkerStop()
    {
        if ($this->_onWorkerStop) {
            call_user_func($this->_onWorkerStop, $this);
        }
        if (is_callable($this->eventHandler . '::onWorkerStop')) {
            call_user_func($this->eventHandler . '::onWorkerStop', $this);
        }
    }

    /**
     * 连接服务注册中心
     * 
     * @return void
     */
    public function connectToRegister()
    {
        $this->_registerConnection = new AsyncTcpConnection("text://{$this->registerAddress}");
        $this->_registerConnection->send('{"event":"worker_connect","secret_key":"' . $this->secretKey . '"}');
        $this->_registerConnection->onClose   = array($this, 'onRegisterConnectionClose');
        $this->_registerConnection->onMessage = array($this, 'onRegisterConnectionMessage');
        $this->_registerConnection->connect();
    }

    /**
     * 与注册中心连接关闭时，定时重连
     *
     * @return void
     */
    public function onRegisterConnectionClose()
    {
        Timer::add(1, array($this, 'connectToRegister'), null, false);
    }

    /**
     * 当注册中心发来消息时
     *
     * @return void
     */
    public function onRegisterConnectionMessage($register_connection, $data)
    {
        $data = json_decode($data, true);
        if (!isset($data['event'])) {
            echo "Received bad data from Register\n";
            return;
        }
        $event = $data['event'];
        switch ($event) {
            case 'broadcast_addresses':
                if (!is_array($data['addresses'])) {
                    echo "Received bad data from Register. Addresses empty\n";
                    return;
                }
                $addresses               = $data['addresses'];
                $this->_gatewayAddresses = array();
                foreach ($addresses as $addr) {
                    $this->_gatewayAddresses[$addr] = $addr;
                }
                $this->checkGatewayConnections($addresses);
                break;
            default:
                echo "Receive bad event:$event from Register.\n";
        }
    }

    /**
     * 当 gateway 转发来数据时
     *
     * @param TcpConnection $connection
     * @param mixed         $data
     */
    public function onGatewayMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        if ($cmd === GatewayProtocol::CMD_PING) {
            return;
        }
        // 上下文数据
        Context::$client_ip     = $data['client_ip'];
        Context::$client_port   = $data['client_port'];
        Context::$local_ip      = $data['local_ip'];
        Context::$local_port    = $data['local_port'];
        Context::$connection_id = $data['connection_id'];
        Context::$client_id     = Context::addressToClientId($data['local_ip'], $data['local_port'],
            $data['connection_id']);
        // $_SERVER 变量
        $_SERVER = array(
            'REMOTE_ADDR'       => long2ip($data['client_ip']),
            'REMOTE_PORT'       => $data['client_port'],
            'GATEWAY_ADDR'      => long2ip($data['local_ip']),
            'GATEWAY_PORT'      => $data['gateway_port'],
            'GATEWAY_CLIENT_ID' => Context::$client_id,
        );
        // 尝试解析 session
        if ($data['ext_data'] != '') {
            Context::$old_session = $_SESSION = Context::sessionDecode($data['ext_data']);
        } else {
            Context::$old_session = $_SESSION = null;
        }

        if ($this->processTimeout) {
            pcntl_alarm($this->processTimeout);
        }
        // 尝试执行 Event::onConnection、Event::onMessage、Event::onClose
        switch ($cmd) {
            case GatewayProtocol::CMD_ON_CONNECTION:
                if ($this->_eventOnConnect) {
                    call_user_func($this->_eventOnConnect, Context::$client_id);
                }
                break;
            case GatewayProtocol::CMD_ON_MESSAGE:
                if ($this->_eventOnMessage) {
                    call_user_func($this->_eventOnMessage, Context::$client_id, $data['body']);
                }
                break;
            case GatewayProtocol::CMD_ON_CLOSE:
                if ($this->_eventOnClose) {
                    call_user_func($this->_eventOnClose, Context::$client_id);
                }
                break;
        }
        if ($this->processTimeout) {
            pcntl_alarm(0);
        }
        
        // session 必须是数组
        if ($_SESSION !== null && !is_array($_SESSION)) {
            throw new \Exception('$_SESSION must be an array. But $_SESSION=' . var_export($_SESSION, true) . ' is not array.');
        }

        // 判断 session 是否被更改
        if ($_SESSION !== Context::$old_session) {
            $session_str_now = $_SESSION !== null ? Context::sessionEncode($_SESSION) : '';
            \GatewayWorker\Lib\Gateway::setSocketSession(Context::$client_id, $session_str_now);
        }

        Context::clear();
    }

    /**
     * 当与 Gateway 的连接断开时触发
     *
     * @param TcpConnection $connection
     * @return  void
     */
    public function onGatewayClose($connection)
    {
        $addr = $connection->remoteAddress;
        unset($this->gatewayConnections[$addr], $this->_connectingGatewayAddresses[$addr]);
        if (isset($this->_gatewayAddresses[$addr]) && !isset($this->_waitingConnectGatewayAddresses[$addr])) {
            Timer::add(1, array($this, 'tryToConnectGateway'), array($addr), false);
            $this->_waitingConnectGatewayAddresses[$addr] = $addr;
        }
    }

    /**
     * 尝试连接 Gateway 内部通讯地址
     *
     * @param string $addr
     */
    public function tryToConnectGateway($addr)
    {
        if (!isset($this->gatewayConnections[$addr]) && !isset($this->_connectingGatewayAddresses[$addr]) && isset($this->_gatewayAddresses[$addr])) {
            $gateway_connection                = new AsyncTcpConnection("GatewayProtocol://$addr");
            $gateway_connection->remoteAddress = $addr;
            $gateway_connection->onConnect     = array($this, 'onConnectGateway');
            $gateway_connection->onMessage     = array($this, 'onGatewayMessage');
            $gateway_connection->onClose       = array($this, 'onGatewayClose');
            $gateway_connection->onError       = array($this, 'onGatewayError');
            if (TcpConnection::$defaultMaxSendBufferSize == $gateway_connection->maxSendBufferSize) {
                $gateway_connection->maxSendBufferSize = 50 * 1024 * 1024;
            }
            $gateway_data         = GatewayProtocol::$empty;
            $gateway_data['cmd']  = GatewayProtocol::CMD_WORKER_CONNECT;
            $gateway_data['body'] = json_encode(array(
                'worker_key' =>"{$this->name}:{$this->id}", 
                'secret_key' => $this->secretKey,
            ));
            $gateway_connection->send($gateway_data);
            $gateway_connection->connect();
            $this->_connectingGatewayAddresses[$addr] = $addr;
        }
        unset($this->_waitingConnectGatewayAddresses[$addr]);
    }

    /**
     * 检查 gateway 的通信端口是否都已经连
     * 如果有未连接的端口，则尝试连接
     *
     * @param array $addresses_list
     */
    public function checkGatewayConnections($addresses_list)
    {
        if (empty($addresses_list)) {
            return;
        }
        foreach ($addresses_list as $addr) {
            if (!isset($this->_waitingConnectGatewayAddresses[$addr])) {
                $this->tryToConnectGateway($addr);
            }
        }
    }

    /**
     * 当连接上 gateway 的通讯端口时触发
     * 将连接 connection 对象保存起来
     *
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnectGateway($connection)
    {
        $this->gatewayConnections[$connection->remoteAddress] = $connection;
        unset($this->_connectingGatewayAddresses[$connection->remoteAddress], $this->_waitingConnectGatewayAddresses[$connection->remoteAddress]);
    }

    /**
     * 当与 gateway 的连接出现错误时触发
     *
     * @param TcpConnection $connection
     * @param int           $error_no
     * @param string        $error_msg
     */
    public function onGatewayError($connection, $error_no, $error_msg)
    {
        echo "GatewayConnection Error : $error_no ,$error_msg\n";
    }

    /**
     * 获取所有 Gateway 内部通讯地址
     *
     * @return array
     */
    public function getAllGatewayAddresses()
    {
        return $this->_gatewayAddresses;
    }

    /**
     * 业务超时回调
     *
     * @param int $signal
     * @throws \Exception
     */
    public function timeoutHandler($signal)
    {
        switch ($signal) {
            // 超时时钟
            case SIGALRM:
                // 超时异常
                $e         = new \Exception("process_timeout", 506);
                $trace_str = $e->getTraceAsString();
                // 去掉第一行timeoutHandler的调用栈
                $trace_str = $e->getMessage() . ":\n" . substr($trace_str, strpos($trace_str, "\n") + 1) . "\n";
                // 开发者没有设置超时处理函数，或者超时处理函数返回空则执行退出
                if (!$this->processTimeoutHandler || !call_user_func($this->processTimeoutHandler, $trace_str, $e)) {
                    Worker::stopAll();
                }
                break;
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
}
