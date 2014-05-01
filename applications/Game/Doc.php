<?php
/**
 * 用户连接gateway后第一次发包会触发此方法
 * @param string $message 一般是传递的账号密码等信息
 * @return void
 */
Event::onConnect($message);

/**
 * 当用户断开连接时触发的方法
 * @param string $address 和该用户gateway通信的地址
 * @param integer $uid 断开连接的用户id 
 * @return void
 */
Event::onClose($uid);

/**
 * 有消息时触发该方法
 * @param int $uid 发消息的uid
 * @param string $message 收到的消息（可以是二进制数据）
 * @return void
 */
Event::onMessage($uid, $message);

/**
 * 向所有用户广播消息
 * @param string $message 消息内容
 * @return void
 */
GateWay::sendToAll($message);
 
/**
 * 向某个用户发消息
 * @param int $uid
 * @param string $message 消息内容
 * @return bool
 */
GateWay::sendToUid($uid, $message);
