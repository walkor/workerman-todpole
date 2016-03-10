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
namespace GatewayWorker\Lib;

/**
 * 数据发送相关
 */
use \Protocols\GatewayProtocol;
use \GatewayWorker\Lib\Context;

class Gateway
{
    /**
     * gateway实例
     * @var object
     */
    protected static $businessWorker = null;

    /**
     * 注册中心地址
     * @var string
     */
    public static $registerAddress = '127.0.0.1:1236';
    
   /**
    * 向所有客户端(或者client_id_array指定的客户端)广播消息
    * @param string $message 向客户端发送的消息
    * @param array $client_id_array 客户端id数组
    */
   public static function sendToAll($message, $client_id_array = null)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $gateway_data['body'] = $message;
       
       if($client_id_array)
       {
           $data_array = array();
           foreach($client_id_array as $client_id)
           {
              $address = Context::clientIdToAddress($client_id);
              $data_array[long2ip($address['local_ip']).":{$address['local_port']}"][$address['connection_id']] = $address['connection_id'];
           }
           foreach($data_array as $addr=>$connection_id_list)
           {
              $the_gateway_data = $gateway_data;
              $the_gateway_data['ext_data'] = call_user_func_array('pack', array_merge(array('N*'), $connection_id_list));
              self::sendToGateway($addr, $the_gateway_data); 
           }
           return;
       }
       elseif(empty($client_id_array) && is_array($client_id_array))
       {
           return;
       }
       
       return self::sendToAllGateway($gateway_data);
   }
   
   /**
    * 向某个客户端发消息
    * @param int $client_id 
    * @param string $message
    */
   public static function sendToClient($client_id, $message)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   } 
   
   /**
    * 向当前客户端发送消息
    * @param string $message
    */
   public static function sendToCurrentClient($message)
   {
       return self::sendCmdAndMessageToClient(null, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   }
   
   /**
    * 判断某个客户端是否在线
    * @param int $client_id
    * @return 0/1
    */
   public static function isOnline($client_id)
   {
       $address_data = Context::clientIdToAddress($client_id);
       $address = long2ip($address_data['local_ip']).":{$address_data['local_port']}";
       if(isset(self::$businessWorker))
       {
           if(!isset(self::$businessWorker->gatewayConnections[$address]))
           {
               return 0;
           }
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_IS_ONLINE;
       $gateway_data['connection_id'] = $address_data['connection_id'];
       return (int)self::sendAndRecv($address, $gateway_data);
   }
   
   /**
    * 获取在线状态，目前返回一个在线client_id数组,client_id为key
    * @return array
    */
   public static function getAllClientInfo($group = null)
   {
       $gateway_data = GatewayProtocol::$empty;
       if(!$group)
       {
           $gateway_data['cmd'] = GatewayProtocol::CMD_GET_ALL_CLIENT_INFO;
       }
       else
       {
           $gateway_data['cmd'] = GatewayProtocol::CMD_GET_CLINET_INFO_BY_GROUP;
           $gateway_data['ext_data'] = $group;
       }
       $status_data = array();
       $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
       foreach($all_buffer_array as $local_ip=>$buffer_array)
       {
           foreach($buffer_array as $local_port=>$buffer)
           {
               $data = json_decode(rtrim($buffer), true);
               if($data)
               {
                   foreach($data as $connection_id=>$session_buffer)
                   {
                       $client_id = Context::addressToClientId($local_ip, $local_port, $connection_id);
                       if($client_id === Context::$client_id)
                       {
                           $status_data[$client_id] = (array)$_SESSION;
                       }
                       else
                       {
                           $status_data[$client_id] = $session_buffer ? Context::sessionDecode($session_buffer) : array();
                       }
                   }
               }
           }
       }
       return $status_data;
   }
  
   /**
    * 获取某个组的成员信息
    * @param string group
    * @return array
    */ 
   public static function getClientInfoByGroup($group)
   {
       return self::getAllClientInfo($group);
   }
   
   /**
    * 获取某个组的成员数目
    * @param string $group
    * @return int
    */
   public static function getClientCountByGroup($group)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_GET_CLIENT_COUNT_BY_GROUP;
       $gateway_data['ext_data'] = $group;
       $total_count = 0;
       $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
       foreach($all_buffer_array as $local_ip=>$buffer_array)
       {
           foreach($buffer_array as $local_port=>$buffer)
           {
               $count = intval($buffer);
               if($count)
               {
                   $total_count += $count;
               }
           }
       }
       return $total_count;
   }
   
   /**
    * 获取与uid绑定的client_id列表
    * @param string $uid
    * @return array
    */
   public static function getClientIdByUid($uid)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_GET_CLIENT_ID_BY_UID;
       $gateway_data['ext_data'] = $uid;
       $client_list = array();
       $all_buffer_array = self::getBufferFromAllGateway($gateway_data);
       foreach($all_buffer_array as $local_ip=>$buffer_array)
       {
           foreach($buffer_array as $local_port=>$buffer)
           {
               $connection_id_array = json_decode(rtrim($buffer), true);
               if($connection_id_array)
               {
                   foreach($connection_id_array as $connection_id)
                   {
                       $client_list[] = Context::addressToClientId($local_ip, $local_port, $connection_id);
                   }
               }
           }
       }
       return $client_list;
   }
   
   /**
    * 批量向所有gateway发包，并得到返回数组
    * @param string $gateway_data
    * @return array
    */
   protected static function getBufferFromAllGateway($gateway_data)
   {
       $gateway_buffer = GatewayProtocol::encode($gateway_data);
       if(isset(self::$businessWorker))
       {
           $all_addresses = self::$businessWorker->getAllGatewayAddresses();
           if(empty($all_addresses))
           {
               throw new \Exception('businessWorker::getAllGatewayAddresses return empty');
           }
       }
       else
       {
           $all_addresses = self::getAllGatewayAddressesFromRegister();
           if(empty($all_addresses))
           {
               return array();
           }
       }
       $client_array = $status_data = $client_address_map = $receive_buffer_array = array();
       // 批量向所有gateway进程发送请求数据
       foreach($all_addresses as $address)
       {
           $client = stream_socket_client("tcp://$address", $errno, $errmsg);
           if($client && strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer))
           {
               $socket_id = (int) $client;
               $client_array[$socket_id] = $client;
               $client_address_map[$socket_id] = explode(':',$address);
               $receive_buffer_array[$socket_id] = '';
           }
       }
       // 超时1秒
       $timeout = 1;
       $time_start = microtime(true);
       // 批量接收请求
       while(count($client_array) > 0)
       {
           $write = $except = array();
           $read = $client_array;
           if(@stream_select($read, $write, $except, $timeout))
           {
               foreach($read as $client)
               {
                   $socket_id = (int)$client;
                   $buffer = stream_socket_recvfrom($client, 65535);
                   if($buffer !== '' && $buffer !== false)
                   {
                       $receive_buffer_array[$socket_id] .= $buffer;
                       if($receive_buffer_array[$socket_id][strlen($receive_buffer_array[$socket_id])-1] === "\n")
                       {
                           unset($client_array[$socket_id]);
                       }
                   }
                   elseif(feof($client))
                   {
                       unset($client_array[$socket_id]);
                   }
               }
           }
           if(microtime(true) - $time_start > $timeout)
           {
               break;
           }
       }
       $format_buffer_array = array();
       foreach($receive_buffer_array as  $socket_id=>$buffer)
       {
           $local_ip = ip2long($client_address_map[$socket_id][0]);
           $local_port = $client_address_map[$socket_id][1];
           $format_buffer_array[$local_ip][$local_port] = $buffer;
       }
       return $format_buffer_array;
   }
   
   /**
    * 关闭某个客户端
    * @param int $client_id
    * @param string $message
    */
   public static function closeClient($client_id)
   {
       if($client_id === Context::$client_id)
       {
           return self::closeCurrentClient();
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address_data = Context::clientIdToAddress($client_id);
           $address = long2ip($address_data['local_ip']).":{$address_data['local_port']}";
           return self::kickAddress($address, $address_data['connection_id']);
       }
   }
   
   /**
    * 踢掉当前客户端
    * @param string $message
    */
   public static function closeCurrentClient()
   {
       if(!Context::$connection_id)
       {
           throw new \Exception('closeCurrentClient can not be called in async context');
       }
       return self::kickAddress(long2ip(Context::$local_ip).':'.Context::$local_port, Context::$connection_id);
   }
   
   /**
    * 将client_id与uid绑定
    * @param int $client_id
    * @param int/string $uid
    */
   public static function bindUid($client_id, $uid)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_BIND_UID, '', $uid);
   }
   
   /**
    * 将client_id与uid解除绑定
    * @param int $client_id
    * @param int/string $uid
    */
   public static function unbindUid($client_id, $uid)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UNBIND_UID, '', $uid);
   }
   
   /**
    * 将client_id加入组
    * @param int $client_id
    * @param int/string $group
    */
   public static function joinGroup($client_id, $group)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_JOIN_GROUP, '', $group);
   }
   
   /**
    * 将client_id离开组
    * @param int $client_id
    * @param int/string $group
    */
   public static function leaveGroup($client_id, $group)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_LEAVE_GROUP, '', $group);
   }
   
   /**
    * 向所有uid发送
    * @param int/string/array $uid
    * @param string $message
    */
   public static function sendToUid($uid, $message)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_UID;
       $gateway_data['body'] = $message;
       
       if(!is_array($uid))
       {
          $uid = array($uid);
       }
        
       $gateway_data['ext_data'] = json_encode($uid);
       
       return self::sendToAllGateway($gateway_data);
   }
   
   /**
    * 向group发送
    * @param int/string/array $group
    * @param string $message
    */
   public static function sendToGroup($group, $message)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_GROUP;
       $gateway_data['body'] = $message;
        
       if(!is_array($group))
       {
           $group = array($group);
       }
   
       $gateway_data['ext_data'] = json_encode($group);
        
       return self::sendToAllGateway($gateway_data);
   }
   
   /**
    * 更新session,框架自动调用，开发者不要调用
    * @param int $client_id
    * @param string $session_str
    */
   public static function updateSocketSession($client_id, $session_str)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_UPDATE_SESSION, '', $session_str);
   }
   
   /**
    * 更新session
    * @param int $client_id
    * @param array $session
    */
   public static function updateSession($client_id, array $session)
   {
       self::updateSocketSession($client_id, Context::sessionEncode($session));
   }
   
   /**
    * 想某个用户网关发送命令和消息
    * @param int $client_id
    * @param int $cmd
    * @param string $message
    * @return boolean
    */
   protected static function sendCmdAndMessageToClient($client_id, $cmd , $message, $ext_data = '')
   {
       // 如果是发给当前用户则直接获取上下文中的地址
       if($client_id === Context::$client_id || $client_id === null)
       {
           $address = long2ip(Context::$local_ip).':'.Context::$local_port;
           $connection_id = Context::$connection_id;
       }
       else
       {
           $address_data = Context::clientIdToAddress($client_id);
           $address = long2ip($address_data['local_ip']).":{$address_data['local_port']}";
           $connection_id = $address_data['connection_id'];
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = $cmd;
       $gateway_data['connection_id'] = $connection_id;
       $gateway_data['body'] = $message;
       if(!empty($ext_data))
       {
           $gateway_data['ext_data'] = $ext_data;
       }
       
       return self::sendToGateway($address, $gateway_data);
   }
   
   /**
    * 发送数据并返回
    * @param int $address
    * @param string $message
    * @return boolean
    */
   protected static function sendAndRecv($address , $data)
   {
       $buffer = GatewayProtocol::encode($data);
       $client = stream_socket_client("tcp://$address", $errno, $errmsg);
       if(!$client)
       {
           throw new \Exception("can not connect to tcp://$address $errmsg");
       }
       if(strlen($buffer) === stream_socket_sendto($client, $buffer))
       {
           $timeout = 1;
           // 阻塞读
           stream_set_blocking($client, 1);
           // 1秒超时
           stream_set_timeout($client, 1);
           $all_buffer = '';
           $time_start = microtime(true);
           while(1)
           {
               $buf = stream_socket_recvfrom($client, 655350);
               if($buf !== '' && $buf !== false)
               {
                   $all_buffer .= $buf;
               }
               else
               {
                   if(feof($client))
                   {
                       throw new \Exception("connection close tcp://$address");
                   }
                   continue;
               }
               // 回复的数据都是以\n结尾
               if(($all_buffer && $all_buffer[strlen($all_buffer)-1] === "\n") || microtime(true) - $time_start > $timeout)
               {
                   break;
               }
           }
           // 返回结果
           return json_decode(rtrim($all_buffer), true);
       }
       else
       {
           throw new \Exception("sendAndRecv($address, \$bufer) fail ! Can not send data!", 502);
       }
   }
   
   /**
    * 发送数据到网关
    * @param string $address
    * @param string $buffer
    */
   protected static function sendToGateway($address, $gateway_data)
   {
       // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
       if(self::$businessWorker)
       {
           if(!isset(self::$businessWorker->gatewayConnections[$address]))
           {
               return false;
           }
           return self::$businessWorker->gatewayConnections[$address]->send($gateway_data);
       }
       // 非workerman环境
       $gateway_buffer = GatewayProtocol::encode($gateway_data);
       $client = stream_socket_client("tcp://$address", $errno, $errmsg);
       return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
   }
   
   /**
    * 向所有gateway发送数据
    * @param string $gateway_data
    */
   protected static function sendToAllGateway($gateway_data)
   {
       // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
       if(self::$businessWorker)
       {
           foreach(self::$businessWorker->gatewayConnections as $gateway_connection)
           {
               $gateway_connection->send($gateway_data);
           }
       }
       // 运行在其它环境中，通过注册中心得到gateway地址
       else
       {
           $all_addresses = self::getAllGatewayAddressesFromRegister();
           if(!$all_addresses)
           {
               throw new \Exception('Gateway::getAllGatewayAddressesFromRegister() with registerAddress:' . self::$registerAddress . '  return ' . var_export($all_addresses, true));
           }
           foreach($all_addresses as $address)
           {
               self::sendToGateway($address, $gateway_data);
           }
       }
   }
   
   /**
    * 踢掉某个网关的socket
    * @param string $local_ip
    * @param int $local_port
    * @param int $client_id
    * @param string $message
    * @param int $client_id
    */
   protected  static function kickAddress($address, $connection_id)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_KICK;
       $gateway_data['connection_id'] = $connection_id;
       return self::sendToGateway($address, $gateway_data);
   }
   
   /**
    * 设置gateway实例
    * @param Bootstrap/Gateway $gateway_instance
    */
   public static function setBusinessWorker($business_worker_instance)
   {
       self::$businessWorker = $business_worker_instance;
   }

   /**
    * 获取通过注册中心获取所有gateway通讯地址
    * @return array
    */
   protected static function getAllGatewayAddressesFromRegister()
   {
       $client = stream_socket_client('tcp://'.self::$registerAddress, $errno, $errmsg, 1);
       if(!$client)
       {
           throw new \Exception('Can not connect to tcp://' . self::$registerAddress . ' ' .$errmsg);
       }
       fwrite($client, '{"event":"worker_connect"}'."\n");
       stream_set_timeout($client, 1);
       $ret = fread($client, 65535);
       if(!$ret || !$data = json_decode(trim($ret), true))
       {
           throw new \Exception('getAllGatewayAddressesFromRegister fail. tcp://' . self::$registerAddress . ' return '.var_export($ret, true));
       }
       return $data['addresses'];
   } 
}

if(!class_exists('\Protocols\GatewayProtocol'))
{
    class_alias('\GatewayWorker\Protocols\GatewayProtocol', 'Protocols\GatewayProtocol');
}
