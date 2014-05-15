<?php
/**
 * 
 * 聊天主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * @author walkor <worker-man@qq.com>
 * 
 */

require_once ROOT_DIR . '/Lib/Gateway.php';
require_once ROOT_DIR . '/Protocols/WebSocket.php';

class Event
{
   /**
    * 当有用户连接时，会触发该方法
    */
   public static function onConnect($message)
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
           // 把时间戳当成uid，todpole程序uid固定为6位数字
           $uid = (substr(strval(microtime(true)), 6, 7)*100)%1000000;
           if($uid<100000)
           {
               $uid += 100000; 
           }
           $new_message .= pack("H*", '811e').'{"type":"welcome","id":'.$uid.'}';
           
           // 记录uid到gateway通信地址的映射
           GateWay::storeUid($uid);
           
           // 发送数据包到address对应的gateway，确认connection成功
           GateWay::notifyConnectionSuccess($uid);
           
           // 发送数据包到客户端 完成握手
           return GateWay::sendToCurrentUid($new_message, true);
       }
       // 如果是flash发来的policy请求
       elseif(trim($message) === '<policy-file-request/>')
       {
           $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>'."\0";
           return GateWay::sendToCurrentUid($policy_xml, true);
       }
       
       return null;
   }
   
   /**
    * 当用户断开连接时
    * @param integer $uid 用户id 
    */
   public static function onClose($uid)
   {
       // 广播 xxx 退出了
       GateWay::sendToAll(json_encode(array('type'=>'closed', 'id'=>$uid)));
   }
   
   /**
    * 有消息时
    * @param int $uid
    * @param string $message
    */
   public static function onMessage($uid, $message)
   {
        // $message len < 7 是用户退出了,直接返回，等待socket关闭触发onclose方法
        if(strlen($message) < 7)
        {
            return ;
        }
        $message = \WebSocket::decode($message);
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
                Gateway::sendToAll(json_encode(
                        array(
                                'type'     => 'update',
                                'id'         => $uid,
                                'angle'   => $message_data["angle"]+0,
                                'momentum' => $message_data["momentum"]+0,
                                'x'                   => $message_data["x"]+0,
                                'y'                   => $message_data["y"]+0,
                                'life'                => 1,
                                'name'           => isset($message_data['name']) ? $message_data['name'] : 'Guest.'.$uid,
                                'authorized'  => false,
                                )
                        ));
                return;
            // 聊天
            case 'message':
                // 向大家说
                $new_message = array(
                    'type'=>'message', 
                    'id'=>$uid,
                    'message'=>$message_data['message'],
                );
                return Gateway::sendToAll(json_encode($new_message));
        }
   }
}
