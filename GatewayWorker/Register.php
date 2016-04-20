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

use Workerman\Worker;
use Workerman\Lib\Timer;

/**
 *
 * 注册中心，用于注册 Gateway 和 BusinessWorker
 *
 * @author walkor<walkor@workerman.net>
 *
 */
class Register extends Worker
{
    /**
     * {@inheritdoc}
     */
    public $name = 'Register';

    /**
     * {@inheritdoc}
     */
    public $reloadable = false;
    
    /**
     * 秘钥
     * @var string
     */
    public $secretKey = '';

    /**
     * 所有 gateway 的连接
     *
     * @var array
     */
    protected $_gatewayConnections = array();

    /**
     * 所有 worker 的连接
     *
     * @var array
     */
    protected $_workerConnections = array();

    /**
     * 进程启动时间
     *
     * @var int
     */
    protected $_startTime = 0;

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        // 设置 onMessage 连接回调
        $this->onConnect = array($this, 'onConnect');

        // 设置 onMessage 回调
        $this->onMessage = array($this, 'onMessage');

        // 设置 onClose 回调
        $this->onClose = array($this, 'onClose');

        // 记录进程启动的时间
        $this->_startTime = time();

        // 运行父方法
        parent::run();
    }

    /**
     * 设置个定时器，将未及时发送验证的连接关闭
     *
     * @param \Workerman\Connection\ConnectionInterface $connection
     * @return void
     */
    public function onConnect($connection)
    {
        $connection->timeout_timerid = Timer::add(10, function () use ($connection) {
            echo "auth timeout\n";
            $connection->close();
        }, null, false);
    }

    /**
     * 设置消息回调
     *
     * @param \Workerman\Connection\ConnectionInterface $connection
     * @param string                                    $data
     * @return void
     */
    public function onMessage($connection, $data)
    {
        // 删除定时器
        Timer::del($connection->timeout_timerid);
        $data       = json_decode($data, true);
        $event      = $data['event'];
        $secret_key = isset($data['secret_key']) ? $data['secret_key'] : '';
        // 开始验证
        switch ($event) {
            // 是 gateway 连接
            case 'gateway_connect':
                if (empty($data['address'])) {
                    echo "address not found\n";
                    return $connection->close();
                }
                if ($secret_key !== $this->secretKey) {
                    echo "Register: Key does not match $secret_key !== {$this->secretKey}\n";
                    return $connection->close();
                }
                $this->_gatewayConnections[$connection->id] = $data['address'];
                $this->broadcastAddresses();
                break;
            // 是 worker 连接
            case 'worker_connect':
                if ($secret_key !== $this->secretKey) {
                    echo "Register: Key does not match $secret_key !== {$this->secretKey}\n";
                    return $connection->close();
                }
                $this->_workerConnections[$connection->id] = $connection;
                $this->broadcastAddresses($connection);
                break;
            case 'ping':
                break;
            default:
                echo "unknown event $event\n";
                $connection->close();
        }
    }

    /**
     * 连接关闭时
     *
     * @param \Workerman\Connection\ConnectionInterface $connection
     */
    public function onClose($connection)
    {
        if (isset($this->_gatewayConnections[$connection->id])) {
            unset($this->_gatewayConnections[$connection->id]);
            $this->broadcastAddresses();
        }
        if (isset($this->_workerConnections[$connection->id])) {
            unset($this->_workerConnections[$connection->id]);
        }
    }

    /**
     * 向 BusinessWorker 广播 gateway 内部通讯地址
     *
     * @param \Workerman\Connection\ConnectionInterface $connection
     */
    public function broadcastAddresses($connection = null)
    {
        $data   = array(
            'event'     => 'broadcast_addresses',
            'addresses' => array_unique(array_values($this->_gatewayConnections)),
        );
        $buffer = json_encode($data);
        if ($connection) {
            $connection->send($buffer);
            return;
        }
        foreach ($this->_workerConnections as $con) {
            $con->send($buffer);
        }
    }
}
