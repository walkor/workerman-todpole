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
namespace GatewayWorker\Lib;

use Exception;
use GatewayWorker\Protocols\GatewayProtocol;
use Workerman\Connection\TcpConnection;

/**
 * 数据发送相关
 */
class Gateway
{
    /**
     * gateway 实例
     *
     * @var object
     */
    protected static $businessWorker = null;

    /**
     * 注册中心地址
     *
     * @var string
     */
    public static $registerAddress = '127.0.0.1:1236';

    /**
     * 秘钥
     * @var string
     */
    public static $secretKey = '';

    /**
     * 链接超时时间
     * @var int
     */
    public static $connectTimeout = 3;

    /**
     * 与Gateway是否是长链接
     * @var bool
     */
    public static $persistentConnection = true;
    
    /**
     * 向所有客户端连接(或者 client_id_array 指定的客户端连接)广播消息
     *
     * @param string $message           向客户端发送的消息
     * @param array  $client_id_array   客户端 id 数组
     * @param array  $exclude_client_id 不给这些client_id发
     * @param bool   $raw               是否发送原始数据（即不调用gateway的协议的encode方法）
     * @return void
     */
    public static function sendToAll($message, $client_id_array = null, $exclude_client_id = null, $raw = false)
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_SEND_TO_ALL;
        $gateway_data['body'] = $message;
        if ($raw) {
            $gateway_data['flag'] |= GatewayProtocol::FLAG_NOT_CALL_ENCODE;
        }

        if ($exclude_client_id) {
            if (!is_array($exclude_client_id)) {
                $exclude_client_id = array($exclude_client_id);
            }
            if ($client_id_array) {
                $exclude_client_id = array_flip($exclude_client_id);
            }
        }

        if ($client_id_array) {
            if (!is_array($client_id_array)) {
                echo new \Exception('bad $client_id_array:'.var_export($client_id_array, true));
                return;
            }
            $data_array = array();
            foreach ($client_id_array as $client_id) {
                if (isset($exclude_client_id[$client_id])) {
                    continue;
                }
                $address                                     = Context::clientIdToAddress($client_id);
                $key                                         = long2ip($address['local_ip']) . ":{$address['local_port']}";
                $data_array[$key][$address['connection_id']] = $address['connection_id'];
            }
            foreach ($data_array as $addr => $connection_id_list) {
                $the_gateway_data             = $gateway_data;
                $the_gateway_data['ext_data'] = json_encode(array('connections' => $connection_id_list));
                self::sendToGateway($addr, $the_gateway_data);
            }
            return;
        } elseif (empty($client_id_array) && is_array($client_id_array)) {
            return;
        }

        if (!$exclude_client_id) {
            return self::sendToAllGateway($gateway_data);
        }

        $address_connection_array = self::clientIdArrayToAddressArray($exclude_client_id);

        // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
        if (self::$businessWorker) {
            foreach (self::$businessWorker->gatewayConnections as $address => $gateway_connection) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('exclude'=> $address_connection_array[$address])) : '';
                /** @var TcpConnection $gateway_connection */
                $gateway_connection->send($gateway_data);
            }
        } // 运行在其它环境中，通过注册中心得到gateway地址
        else {
            $all_addresses = self::getAllGatewayAddressesFromRegister();
            if (!$all_addresses) {
                throw new Exception('Gateway::getAllGatewayAddressesFromRegister() with registerAddress:' .
                    self::$registerAddress . '  return ' . var_export($all_addresses, true));
            }
            foreach ($all_addresses as $address) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('exclude'=> $address_connection_array[$address])) : '';
                self::sendToGateway($address, $gateway_data);
            }
        }

    }

    /**
     * 向某个客户端连接发消息
     *
     * @param int    $client_id
     * @param string $message
     * @return bool
     */
    public static function sendToClient($client_id, $message)
    {
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SEND_TO_ONE, $message);
    }

    /**
     * 向当前客户端连接发送消息
     *
     * @param string $message
     * @return bool
     */
    public static function sendToCurrentClient($message)
    {
        return self::sendCmdAndMessageToClient(null, GatewayProtocol::CMD_SEND_TO_ONE, $message);
    }

    /**
     * 判断某个uid是否在线
     *
     * @param string $uid
     * @return int 0|1
     */
    public static function isUidOnline($uid)
    {
        return (int)self::getClientIdByUid($uid);
    }
    
    /**
     * 判断某个客户端连接是否在线
     *
     * @param int $client_id
     * @return int 0|1
     */
    public static function isOnline($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        $address      = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
        if (isset(self::$businessWorker)) {
            if (!isset(self::$businessWorker->gatewayConnections[$address])) {
                return 0;
            }
        }
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_IS_ONLINE;
        $gateway_data['connection_id'] = $address_data['connection_id'];
        return (int)self::sendAndRecv($address, $gateway_data);
    }

    /**
     * 获取所有在线用户的session，client_id为 key
     *
     * @param string $group
     * @return array
     */
    public static function getAllClientInfo($group = null)
    {
        return self::getAllClientSessions($group);
    }

    /**
     * 获取所有在线用户的session，client_id为 key
     *
     * @param string $group
     * @return array
     */
    public static function getAllClientSessions($group = null)
    {
        $gateway_data = GatewayProtocol::$empty;
        if (!$group) {
            $gateway_data['cmd'] = GatewayProtocol::CMD_GET_ALL_CLIENT_INFO;
        } else {
            $gateway_data['cmd']      = GatewayProtocol::CMD_GET_CLINET_INFO_BY_GROUP;
            $gateway_data['ext_data'] = $group;
        }
        $status_data      = array();
        $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $data) {
                if ($data) {
                    foreach ($data as $connection_id => $session_buffer) {
                        $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                        if ($client_id === Context::$client_id) {
                            $status_data[$client_id] = (array)$_SESSION;
                        } else {
                            $status_data[$client_id] = $session_buffer ? Context::sessionDecode($session_buffer) : array();
                        }
                    }
                }
            }
        }
        return $status_data;
    }

    /**
     * 获取某个组的连接信息
     *
     * @param string $group
     * @return array
     */
    public static function getClientInfoByGroup($group)
    {
        return self::getAllClientSessions($group);
    }

    /**
     * 获取某个组的连接信息
     *
     * @param string $group
     * @return array
     */
    public static function getClientSessionsByGroup($group)
    {
        return self::getAllClientSessions($group);
    }
    
    /**
     * 获取所有连接数
     *
     * @return int
     */
    public static function getAllClientCount()
    {
        return self::getClientCountByGroup();
    }

    /**
     * 获取某个组的在线连接数
     *
     * @param string $group
     * @return int
     */
    public static function getClientCountByGroup($group = '')
    {
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_GET_CLIENT_COUNT_BY_GROUP;
        $gateway_data['ext_data'] = $group;
        $total_count              = 0;
        $all_buffer_array         = self::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $count) {
                if ($count) {
                    $total_count += $count;
                }
            }
        }
        return $total_count;
    }

    /**
     * 获取与 uid 绑定的 client_id 列表
     *
     * @param string $uid
     * @return array
     */
    public static function getClientIdByUid($uid)
    {
        $gateway_data             = GatewayProtocol::$empty;
        $gateway_data['cmd']      = GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID;
        $gateway_data['ext_data'] = $uid;
        $client_list              = array();
        $all_buffer_array         = self::getBufferFromAllGateway($gateway_data);
        foreach ($all_buffer_array as $local_ip => $buffer_array) {
            foreach ($buffer_array as $local_port => $connection_id_array) {
                if ($connection_id_array) {
                    foreach ($connection_id_array as $connection_id) {
                        $client_list[] = Context::addressToClientId($local_ip, $local_port, $connection_id);
                    }
                }
            }
        }
        return $client_list;
    }
    
    /**
     * 生成验证包，用于验证此客户端的合法性
     * 
     * @return string
     */
    protected static function generateAuthBuffer()
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_GATEWAY_CLIENT_CONNECT;
        $gateway_data['body'] = json_encode(array(
            'secret_key' => self::$secretKey,
        ));
        return GatewayProtocol::encode($gateway_data);
    }

    /**
     * 批量向所有 gateway 发包，并得到返回数组
     *
     * @param string $gateway_data
     * @return array
     * @throws Exception
     */
    protected static function getBufferFromAllGateway($gateway_data)
    {
        $gateway_buffer = GatewayProtocol::encode($gateway_data);
        $gateway_buffer = self::$secretKey ? self::generateAuthBuffer() . $gateway_buffer : $gateway_buffer;
        if (isset(self::$businessWorker)) {
            $all_addresses = self::$businessWorker->getAllGatewayAddresses();
            if (empty($all_addresses)) {
                throw new Exception('businessWorker::getAllGatewayAddresses return empty');
            }
        } else {
            $all_addresses = self::getAllGatewayAddressesFromRegister();
            if (empty($all_addresses)) {
                return array();
            }
        }
        $client_array = $status_data = $client_address_map = $receive_buffer_array = $recv_length_array = array();
        // 批量向所有gateway进程发送请求数据
        foreach ($all_addresses as $address) {
            $client = stream_socket_client("tcp://$address", $errno, $errmsg, self::$connectTimeout);
            if ($client && strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer)) {
                $socket_id                        = (int)$client;
                $client_array[$socket_id]         = $client;
                $client_address_map[$socket_id]   = explode(':', $address);
                $receive_buffer_array[$socket_id] = '';
            }
        }
        // 超时1秒
        $timeout    = 1;
        $time_start = microtime(true);
        // 批量接收请求
        while (count($client_array) > 0) {
            $write = $except = array();
            $read  = $client_array;
            if (@stream_select($read, $write, $except, $timeout)) {
                foreach ($read as $client) {
                    $socket_id = (int)$client;
                    $buffer    = stream_socket_recvfrom($client, 65535);
                    if ($buffer !== '' && $buffer !== false) {
                        $receive_buffer_array[$socket_id] .= $buffer;
                        $receive_length = strlen($receive_buffer_array[$socket_id]);
                        if (empty($recv_length_array[$socket_id]) && $receive_length >= 4) {
                            $recv_length_array[$socket_id] = current(unpack('N', $receive_buffer_array[$socket_id]));
                        }
                        if (!empty($recv_length_array[$socket_id]) && $receive_length >= $recv_length_array[$socket_id] + 4) {
                            unset($client_array[$socket_id]);
                        }
                    } elseif (feof($client)) {
                        unset($client_array[$socket_id]);
                    }
                }
            }
            if (microtime(true) - $time_start > $timeout) {
                break;
            }
        }
        $format_buffer_array = array();
        foreach ($receive_buffer_array as $socket_id => $buffer) {
            $local_ip                                    = ip2long($client_address_map[$socket_id][0]);
            $local_port                                  = $client_address_map[$socket_id][1];
            $format_buffer_array[$local_ip][$local_port] = unserialize(substr($buffer, 4));
        }
        return $format_buffer_array;
    }

    /**
     * 关闭某个客户端
     *
     * @param int $client_id
     * @return bool
     */
    public static function closeClient($client_id)
    {
        if ($client_id === Context::$client_id) {
            return self::closeCurrentClient();
        } // 不是发给当前用户则使用存储中的地址
        else {
            $address_data = Context::clientIdToAddress($client_id);
            $address      = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
            return self::kickAddress($address, $address_data['connection_id']);
        }
    }

    /**
     * 踢掉当前客户端
     *
     * @return bool
     * @throws Exception
     */
    public static function closeCurrentClient()
    {
        if (!Context::$connection_id) {
            throw new Exception('closeCurrentClient can not be called in async context');
        }
        return self::kickAddress(long2ip(Context::$local_ip) . ':' . Context::$local_port, Context::$connection_id);
    }

    /**
     * 将 client_id 与 uid 绑定
     *
     * @param int        $client_id
     * @param int|string $uid
     * @return bool
     */
    public static function bindUid($client_id, $uid)
    {
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_BIND_UID, '', $uid);
    }

    /**
     * 将 client_id 与 uid 解除绑定
     *
     * @param int        $client_id
     * @param int|string $uid
     * @return bool
     */
    public static function unbindUid($client_id, $uid)
    {
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UNBIND_UID, '', $uid);
    }

    /**
     * 将 client_id 加入组
     *
     * @param int        $client_id
     * @param int|string $group
     * @return bool
     */
    public static function joinGroup($client_id, $group)
    {
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_JOIN_GROUP, '', $group);
    }

    /**
     * 将 client_id 离开组
     *
     * @param int        $client_id
     * @param int|string $group
     * @return bool
     */
    public static function leaveGroup($client_id, $group)
    {
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_LEAVE_GROUP, '', $group);
    }

    /**
     * 向所有 uid 发送
     *
     * @param int|string|array $uid
     * @param string           $message
     */
    public static function sendToUid($uid, $message)
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_SEND_TO_UID;
        $gateway_data['body'] = $message;

        if (!is_array($uid)) {
            $uid = array($uid);
        }

        $gateway_data['ext_data'] = json_encode($uid);

        self::sendToAllGateway($gateway_data);
    }

    /**
     * 向 group 发送
     *
     * @param int|string|array $group             组（不允许是 0 '0' false null array()等为空的值）
     * @param string           $message           消息
     * @param array            $exclude_client_id 不给这些client_id发
     * @param bool             $raw               发送原始数据（即不调用gateway的协议的encode方法）
     */
    public static function sendToGroup($group, $message, $exclude_client_id = null, $raw = false)
    {
        $gateway_data         = GatewayProtocol::$empty;
        $gateway_data['cmd']  = GatewayProtocol::CMD_SEND_TO_GROUP;
        $gateway_data['body'] = $message;
        if ($raw) {
            $gateway_data['flag'] |= GatewayProtocol::FLAG_NOT_CALL_ENCODE;
        }

        if (!is_array($group)) {
            $group = array($group);
        }

        // 分组发送，没有排除的client_id，直接发送
        $default_ext_data_buffer = json_encode(array('group'=> $group, 'exclude'=> null));
        if (empty($exclude_client_id)) {
            $gateway_data['ext_data'] = $default_ext_data_buffer;
            return self::sendToAllGateway($gateway_data);
        }

        // 分组发送，有排除的client_id，需要将client_id转换成对应gateway进程内的connectionId
        if (!is_array($exclude_client_id)) {
            $exclude_client_id = array($exclude_client_id);
        }

        $address_connection_array = self::clientIdArrayToAddressArray($exclude_client_id);
        // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
        if (self::$businessWorker) {
            foreach (self::$businessWorker->gatewayConnections as $address => $gateway_connection) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('group'=> $group, 'exclude'=> $address_connection_array[$address])) :
                    $default_ext_data_buffer;
                /** @var TcpConnection $gateway_connection */
                $gateway_connection->send($gateway_data);
            }
        } // 运行在其它环境中，通过注册中心得到gateway地址
        else {
            $all_addresses = self::getAllGatewayAddressesFromRegister();
            if (!$all_addresses) {
                throw new Exception('Gateway::getAllGatewayAddressesFromRegister() with registerAddress:' .
                    self::$registerAddress . '  return ' . var_export($all_addresses, true));
            }
            foreach ($all_addresses as $address) {
                $gateway_data['ext_data'] = isset($address_connection_array[$address]) ?
                    json_encode(array('group'=> $group, 'exclude'=> array_values($address_connection_array[$address]))) :
                    $default_ext_data_buffer;
                self::sendToGateway($address, $gateway_data);
            }
        }
    }

    /**
     * 更新 session，框架自动调用，开发者不要调用
     *
     * @param int    $client_id
     * @param string $session_str
     * @return bool
     */
    public static function setSocketSession($client_id, $session_str)
    {
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SET_SESSION, '', $session_str);
    }

    /**
     * 设置 session，原session值会被覆盖
     *
     * @param int   $client_id
     * @param array $session
     */
    public static function setSession($client_id, array $session)
    {
        if (Context::$client_id === $client_id) {
            $_SESSION = $session;
            Context::$old_session = $_SESSION;
        }
        return self::setSocketSession($client_id, Context::sessionEncode($session));
    }
    
    /**
     * 更新 session，实际上是与老的session合并
     *
     * @param int   $client_id
     * @param array $session
     */
    public static function updateSession($client_id, array $session)
    {
        if (Context::$client_id === $client_id) {
            $_SESSION = $session + (array)$_SESSION;
            Context::$old_session = $_SESSION;
        }
        return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UPDATE_SESSION, '', Context::sessionEncode($session));
    }
    
    /**
     * 获取某个client_id的session
     *
     * @param int   $client_id
     * @return mixed false表示出错、null表示用户不存在、array表示具体的session信息 
     */
    public static function getSession($client_id)
    {
        $address_data = Context::clientIdToAddress($client_id);
        $address      = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
        if (isset(self::$businessWorker)) {
            if (!isset(self::$businessWorker->gatewayConnections[$address])) {
                return null;
            }
        }
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_GET_SESSION_BY_CLIENT_ID;
        $gateway_data['connection_id'] = $address_data['connection_id'];
        return self::sendAndRecv($address, $gateway_data);
    }

    /**
     * 想某个用户网关发送命令和消息
     *
     * @param int    $client_id
     * @param int    $cmd
     * @param string $message
     * @param string $ext_data
     * @return boolean
     */
    protected static function sendCmdAndMessageToClient($client_id, $cmd, $message, $ext_data = '')
    {
        // 如果是发给当前用户则直接获取上下文中的地址
        if ($client_id === Context::$client_id || $client_id === null) {
            $address       = long2ip(Context::$local_ip) . ':' . Context::$local_port;
            $connection_id = Context::$connection_id;
        } else {
            $address_data  = Context::clientIdToAddress($client_id);
            $address       = long2ip($address_data['local_ip']) . ":{$address_data['local_port']}";
            $connection_id = $address_data['connection_id'];
        }
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = $cmd;
        $gateway_data['connection_id'] = $connection_id;
        $gateway_data['body']          = $message;
        if (!empty($ext_data)) {
            $gateway_data['ext_data'] = $ext_data;
        }

        return self::sendToGateway($address, $gateway_data);
    }

    /**
     * 发送数据并返回
     *
     * @param int   $address
     * @param mixed $data
     * @return bool
     * @throws Exception
     */
    protected static function sendAndRecv($address, $data)
    {
        $buffer = GatewayProtocol::encode($data);
        $buffer = self::$secretKey ? self::generateAuthBuffer() . $buffer : $buffer;
        $client = stream_socket_client("tcp://$address", $errno, $errmsg, self::$connectTimeout);
        if (!$client) {
            throw new Exception("can not connect to tcp://$address $errmsg");
        }
        if (strlen($buffer) === stream_socket_sendto($client, $buffer)) {
            $timeout = 1;
            // 阻塞读
            stream_set_blocking($client, 1);
            // 1秒超时
            stream_set_timeout($client, 1);
            $all_buffer = '';
            $time_start = microtime(true);
            $pack_len = 0;
            while (1) {
                $buf = stream_socket_recvfrom($client, 655350);
                if ($buf !== '' && $buf !== false) {
                    $all_buffer .= $buf;
                } else {
                    if (feof($client)) {
                        throw new Exception("connection close tcp://$address");
                    } elseif (microtime(true) - $time_start > $timeout) {
                        break;
                    }
                    continue;
                }
                $recv_len = strlen($all_buffer);
                if (!$pack_len && $recv_len >= 4) {
                    $pack_len= current(unpack('N', $all_buffer));
                }
                // 回复的数据都是以\n结尾
                if (($pack_len && $recv_len >= $pack_len + 4) || microtime(true) - $time_start > $timeout) {
                    break;
                }
            }
            // 返回结果
            return unserialize(substr($all_buffer, 4));
        } else {
            throw new Exception("sendAndRecv($address, \$bufer) fail ! Can not send data!", 502);
        }
    }

    /**
     * 发送数据到网关
     *
     * @param string $address
     * @param array  $gateway_data
     * @return bool
     */
    protected static function sendToGateway($address, $gateway_data)
    {
        return self::sendBufferToGateway($address, GatewayProtocol::encode($gateway_data));
    }

    /**
     * 发送buffer数据到网关
     * @param string $address
     * @param string $gateway_buffer
     * @return bool
     */
    protected static function sendBufferToGateway($address, $gateway_buffer)
    {
        // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
        if (self::$businessWorker) {
            if (!isset(self::$businessWorker->gatewayConnections[$address])) {
                return false;
            }
            return self::$businessWorker->gatewayConnections[$address]->send($gateway_buffer, true);
        }
        // 非workerman环境
        $gateway_buffer = self::$secretKey ? self::generateAuthBuffer() . $gateway_buffer : $gateway_buffer;
        $flag           = self::$persistentConnection ? STREAM_CLIENT_PERSISTENT | STREAM_CLIENT_CONNECT : STREAM_CLIENT_CONNECT;
        $client         = stream_socket_client("tcp://$address", $errno, $errmsg, self::$connectTimeout, $flag);
        return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
    }

    /**
     * 向所有 gateway 发送数据
     *
     * @param string $gateway_data
     * @throws Exception
     */
    protected static function sendToAllGateway($gateway_data)
    {
        $buffer = GatewayProtocol::encode($gateway_data);
        // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
        if (self::$businessWorker) {
            foreach (self::$businessWorker->gatewayConnections as $gateway_connection) {
                /** @var TcpConnection $gateway_connection */
                $gateway_connection->send($buffer, true);
            }
        } // 运行在其它环境中，通过注册中心得到gateway地址
        else {
            $all_addresses = self::getAllGatewayAddressesFromRegister();
            if (!$all_addresses) {
                throw new Exception('Gateway::getAllGatewayAddressesFromRegister() with registerAddress:' .
                    self::$registerAddress . '  return ' . var_export($all_addresses, true));
            }
            foreach ($all_addresses as $address) {
                self::sendBufferToGateway($address, $buffer);
            }
        }
    }

    /**
     * 踢掉某个网关的 socket
     *
     * @param string $address
     * @param int    $connection_id
     * @return bool
     */
    protected static function kickAddress($address, $connection_id)
    {
        $gateway_data                  = GatewayProtocol::$empty;
        $gateway_data['cmd']           = GatewayProtocol::CMD_KICK;
        $gateway_data['connection_id'] = $connection_id;
        return self::sendToGateway($address, $gateway_data);
    }

    /**
     * 将clientid数组转换成address数组
     *
     * @param array $client_id_array
     * @return array
     */
    protected static function clientIdArrayToAddressArray(array $client_id_array)
    {
        $address_connection_array = array();
        foreach ($client_id_array as $client_id) {
            $address_data                                                       = Context::clientIdToAddress($client_id);
            $address                                                            = long2ip($address_data['local_ip']) .
                ":{$address_data['local_port']}";
            $address_connection_array[$address][$address_data['connection_id']] = $address_data['connection_id'];
        }
        return $address_connection_array;
    }

    /**
     * 设置 gateway 实例
     *
     * @param \GatewayWorker\BusinessWorker $business_worker_instance
     */
    public static function setBusinessWorker($business_worker_instance)
    {
        self::$businessWorker = $business_worker_instance;
    }

    /**
     * 获取通过注册中心获取所有 gateway 通讯地址
     *
     * @return array
     * @throws Exception
     */
    protected static function getAllGatewayAddressesFromRegister()
    {
        static $addresses_cache, $last_update;
        $time_now = time();
        $expiration_time = 1;
        if(empty($addresses_cache) || $time_now - $last_update > $expiration_time) {
            $client = stream_socket_client('tcp://' . self::$registerAddress, $errno, $errmsg, self::$connectTimeout);
            if (!$client) {
                throw new Exception('Can not connect to tcp://' . self::$registerAddress . ' ' . $errmsg);
            }
            fwrite($client, '{"event":"worker_connect","secret_key":"' . self::$secretKey . '"}' . "\n");
            stream_set_timeout($client, 1);
            $ret = fgets($client, 655350);
            if (!$ret || !$data = json_decode(trim($ret), true)) {
                throw new Exception('getAllGatewayAddressesFromRegister fail. tcp://' .
                    self::$registerAddress . ' return ' . var_export($ret, true));
            }
            $last_update = $time_now;
            $addresses_cache = $data['addresses'];
        }
        return $addresses_cache;
    }
}

if (!class_exists('\Protocols\GatewayProtocol')) {
    class_alias('GatewayWorker\Protocols\GatewayProtocol', 'Protocols\GatewayProtocol');
}
