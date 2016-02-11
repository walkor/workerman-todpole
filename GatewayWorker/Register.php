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
namespace GatewayWorker;

use Workerman\Connection\TcpConnection;

use \Workerman\Worker;
use \Workerman\Lib\Timer;
use \Workerman\Autoloader;
use \GatewayWorker\Protocols\GatewayProtocol;

/**
 * 
 * 注册中心，用于注册Gateway和BusinessWorker 
 * 
 * @author walkor<walkor@workerman.net>
 *
 */
class Register extends Worker
{
    /*
     * 进程名
     * @var string
     */
    public $name = 'Register';
 
    /**
     * 是否可以平滑重启，Register不平滑重启
     * @var bool
     */
    public $reloadable = false;
   
    /**
     * 所有gateway的连接
     * @var array
     */ 
    protected $_gatewayConnections = array();

    /**
     * 所有worker的连接
     * @var array
     */
    protected $_workerConnections = array();   

    /**
     * 运行
     * @return void
     */
    public function run()
    {
        // 设置onMessage连接回调
        $this->onConnect = array($this, 'onConnect');
        
        // 设置onMessage回调
        $this->onMessage = array($this, 'onMessage');
        
        // 设置onClose回调 
        $this->onClose = array($this, 'onClose');
        
        // 记录进程启动的时间
        $this->_startTime = time();

        // 运行父方法
        parent::run();
    }

    /**
     * 设置个定时器，将未及时发送验证的连接关闭
     * @return void
     */
    public function onConnect($connection)
    {
         $connection->timeout_timerid = Timer::add(10, function()use($connection){
             echo "auth timeout\n";
             $connection->close();
         }, null, false);
    }

    /**
     * 设置消息回调
     * @return void
     */
    public function onMessage($connection, $data)
    {
        // 删除定时器
        Timer::del($connection->timeout_timerid);
        $data = json_decode($data, true);
        $event = $data['event'];
        // 开始验证
        switch($event)
        {
            // 是geteway连接
            case 'gateway_connect':
                if(empty($data['address']))
                {
                    echo "address not found\n";
                    return $connection->close();
                }
                $this->_gatewayConnections[$connection->id] = $data['address'];
                $this->broadcastAddresses();
                break;
           // 是worker连接
           case 'worker_connect':
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
     * @return void
     */
    public function onClose($connection)
    {
        if(isset($this->_gatewayConnections[$connection->id]))
        {
            unset($this->_gatewayConnections[$connection->id]);
            $this->broadcastAddresses();
        }
        if(isset($this->_workerConnections[$connection->id]))
        {
            unset($this->_workerConnections[$connection->id]);
        }
    }

    /**
     * 向BusinessWorker广播gateway内部通讯地址
     * @return void
     */
    public function broadcastAddresses($connection = null)
    {
        $data = array(
            'event' => 'broadcast_addresses',
            'addresses' => array_unique(array_values($this->_gatewayConnections)),
        );
        $buffer = json_encode($data);
        if($connection)
        {
            return $connection->send($buffer);
        }
        foreach($this->_workerConnections as $con)
        {
            $con->send($buffer);
        }
    }
}



















