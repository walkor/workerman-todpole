<?php
/**
 * 
 * 聊天主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * @author walkor <worker-man@qq.com>
 * 
 */

require_once WORKERMAN_ROOT_DIR . 'applications/Chat/Gateway.php';
require_once WORKERMAN_ROOT_DIR . 'applications/Common/Protocols/WebSocket.php';

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
           
           // 把时间戳当成uid
           $uid = substr(strval(microtime(true)), 3, 10)*100;
           
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
       // [这步是必须的]删除这个用户的gateway通信地址
       GateWay::deleteUidAddress($uid);

       // 从用户列表中删除
       self::delUserFromList($uid);

       // 广播 xxx 退出了
       GateWay::sendToAll(json_encode(array('type'=>'logout', 'uid'=> $uid, 'time'=>date('Y-m-d H:i:s'))));
       
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
        $message = \App\Common\Protocols\WebSocket::decode($message);
        echo "uid:$uid onMessage:".var_export($message,true)."\n";
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        switch($message_data['type'])
        {
            // 用户登录 message格式: {type:login, name:xx} ，添加到用户，广播给所有用户xx进入聊天室
            case 'login':
                // 存储当前用户到用户列表
                self::addUserToList($uid, htmlspecialchars($message_data['name']));
                // 获取用户列表
                $user_list = self::getUserList();
                // 整理用户列表以便显示
                $all_users = array();
                if($user_list)
                {
                    foreach($user_list as $tmp_uid=>$name)
                    {
                        $all_users[] = array('uid'=>$tmp_uid, 'name'=>$name);
                    }
                }
                
                // 发送给当前用户 内容是用户列表 message: {type:user_list, user_list:xxxx}
                Gateway::sendToUid($uid, json_encode(array('type'=>'user_list', 'user_list'=> $all_users)));
                
                // 转播给所有用户，xx进入聊天室 message {type:login, uid:xx, name:xx} 
                Gateway::sendToAll(json_encode(array('type'=>'login', 'uid'=>$uid, 'name'=>htmlspecialchars($message_data['name']), 'time'=>date('Y-m-d H:i:s'))));
                return;
                
            // 用户发言 message: {type:say, to_uid:xx, content:xx}
            case 'say':
                // 私聊
                if($message_data['to_uid'] != 'all')
                {
                    $new_message = array(
                        'type'=>'say',
                        'from_uid'=>$uid, 
                        'to_uid'=>$message_data['to_uid'],
                        'content'=>nl2br(htmlspecialchars($message_data['content'])),
                        'time'=>date('Y-m-d :i:s'),
                    );
                    return Gateway::sendToUid($message_data['to_uid'], json_encode($new_message));
                }
                // 向大家说
                $new_message = array(
                    'type'=>'say', 
                    'from_uid'=>$uid,
                    'to_uid'=>'all',
                    'content'=>nl2br(htmlspecialchars($message_data['content'])),
                    'time'=>date('Y-m-d :i:s'),
                );
                return Gateway::sendToAll(json_encode($new_message));
                
        }
   }
   
   /**
    * 获得用户列表
    * @todo 保存有限个
    */
   public static function getUserList()
   {
       $key = 'alluserlist';
       return Store::get($key);
   }
   
   /**
    * 从用户列表中删除一个用户
    * @param int $uid
    */
   public static function delUserFromList($uid)
   {
       $key = 'alluserlist';
       $user_list = self::getUserList();
       if(isset($user_list[$uid]))
       {
           unset($user_list[$uid]);
           return Store::set($key, $user_list);
       }
       return true;
   }
   
   /**
    * 添加到用户列表中
    * @param int $uid
    * @param string $name
    */
   public static function addUserToList($uid, $name)
   {
       $key = 'alluserlist';
       $user_list = self::getUserList();
       if(!isset($user_list[$uid]))
       {
           $user_list[$uid] = $name;
           return Store::set($key, $user_list);
       }
       return true;
   }
   
}
