<?php
/**
 * 
 * 主逻辑
 * 主要是处理 onGatewayMessage onMessage onClose 三个方法
 * @author walkor <walkor@workerman.net>
 * 
 */

use \Lib\Context;
use \Lib\Gateway;
use \Lib\StatisticClient;
use \Lib\Store;
use \Protocols\GatewayProtocol;
use \Protocols\WebSocket;

class Event
{
    /**
     * 网关有消息时，判断消息是否完整，分包
     */
    public static function onGatewayMessage($buffer)
    {
        // 根据websocket协议判断数据是否接收完整
        return WebSocket::check($buffer);
    }
    
   /**
    * 有消息时
    * @param int $client_id
    * @param string $message
    */
   public static function onMessage($client_id, $message)
   {
       // 如果是websocket握手
       if(self::checkHandshake($message))
       {
           $new_message ='{"type":"welcome","id":'.$client_id.'}';
           // 发送数据包到客户端 
           return GateWay::sendToCurrentClient(WebSocket::encode($new_message));
           return;
       }
       
       // websocket 通知连接即将关闭
       if(WebSocket::isClosePacket($message))
        {
            Gateway::kickClient($client_id, '');
            return;
        }
        
        // 获取客户端原始请求
        $message = WebSocket::decode($message);
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        switch($message_data['type'])
        {
            // 更新用户
            case 'update':
                // 转播给所有用户
                Gateway::sendToAll(WebSocket::encode(json_encode(
                        array(
                                'type'     => 'update',
                                'id'         => $client_id,
                                'angle'   => $message_data["angle"]+0,
                                'momentum' => $message_data["momentum"]+0,
                                'x'                   => $message_data["x"]+0,
                                'y'                   => $message_data["y"]+0,
                                'life'                => 1,
                                'name'           => isset($message_data['name']) ? $message_data['name'] : 'Guest.'.$client_id,
                                'authorized'  => false,
                                )
                        )));
                return;
            // 聊天
            case 'message':
                // 向大家说
                $new_message = array(
                    'type'=>'message', 
                    'id'=>$client_id,
                    'message'=>$message_data['message'],
                );
                return Gateway::sendToAll(WebSocket::encode(json_encode($new_message)));
        }
   }
   
   /**
    * 当用户断开连接时
    * @param integer $client_id 用户id
    */
   public static function onClose($client_id)
   {
       // 广播 xxx 退出了
       GateWay::sendToAll(WebSocket::encode(json_encode(array('type'=>'closed', 'id'=>$client_id))));
   }
   
   /**
    * websocket协议握手
    * @param string $message
    */
   public static function checkHandshake($message)
   {
       // WebSocket 握手阶段
       if(0 === strpos($message, 'GET'))
       {
           // 解析Sec-WebSocket-Key
           $Sec_WebSocket_Key = '';
           if(preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/", $message, $match))
           {
               $Sec_WebSocket_Key = $match[1];
           }
           $new_key = base64_encode(sha1($Sec_WebSocket_Key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
           // 握手返回的数据
           $new_message = "HTTP/1.1 101 Switching Protocols\r\n";
           $new_message .= "Upgrade: websocket\r\n";
           $new_message .= "Sec-WebSocket-Version: 13\r\n";
           $new_message .= "Connection: Upgrade\r\n";
           $new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
   
           // 发送数据包到客户端 完成握手
           Gateway::sendToCurrentClient($new_message);
           return true;
       }
       // 如果是flash发来的policy请求
       elseif(trim($message) === '<policy-file-request/>')
       {
           $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>'."\0";
           Gateway::sendToCurrentClient($policy_xml);
           return true;
       }
       return false;
   }
}
