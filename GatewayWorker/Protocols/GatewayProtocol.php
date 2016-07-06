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
namespace GatewayWorker\Protocols;

/**
 * Gateway 与 Worker 间通讯的二进制协议
 *
 * struct GatewayProtocol
 * {
 *     unsigned int        pack_len,
 *     unsigned char       cmd,//命令字
 *     unsigned int        local_ip,
 *     unsigned short      local_port,
 *     unsigned int        client_ip,
 *     unsigned short      client_port,
 *     unsigned int        connection_id,
 *     unsigned char       flag,
 *     unsigned short      gateway_port,
 *     unsigned int        ext_len,
 *     char[ext_len]       ext_data,
 *     char[pack_length-HEAD_LEN] body//包体
 * }
 * NCNnNnNCnN
 */
class GatewayProtocol
{
    // 发给worker，gateway有一个新的连接
    const CMD_ON_CONNECTION = 1;

    // 发给worker的，客户端有消息
    const CMD_ON_MESSAGE = 3;

    // 发给worker上的关闭链接事件
    const CMD_ON_CLOSE = 4;

    // 发给gateway的向单个用户发送数据
    const CMD_SEND_TO_ONE = 5;

    // 发给gateway的向所有用户发送数据
    const CMD_SEND_TO_ALL = 6;

    // 发给gateway的踢出用户
    const CMD_KICK = 7;

    // 发给gateway，通知用户session更新
    const CMD_UPDATE_SESSION = 9;

    // 获取在线状态
    const CMD_GET_ALL_CLIENT_INFO = 10;

    // 判断是否在线
    const CMD_IS_ONLINE = 11;

    // client_id绑定到uid
    const CMD_BIND_UID = 12;

    // 解绑
    const CMD_UNBIND_UID = 13;

    // 向uid发送数据
    const CMD_SEND_TO_UID = 14;

    // 根据uid获取绑定的clientid
    const CMD_GET_CLIENT_ID_BY_UID = 15;

    // 加入组
    const CMD_JOIN_GROUP = 20;

    // 离开组
    const CMD_LEAVE_GROUP = 21;

    // 向组成员发消息
    const CMD_SEND_TO_GROUP = 22;

    // 获取组成员
    const CMD_GET_CLINET_INFO_BY_GROUP = 23;

    // 获取组成员数
    const CMD_GET_CLIENT_COUNT_BY_GROUP = 24;

    // worker连接gateway事件
    const CMD_WORKER_CONNECT = 200;

    // 心跳
    const CMD_PING = 201;

    // GatewayClient连接gateway事件
    const CMD_GATEWAY_CLIENT_CONNECT = 202;

    // 根据client_id获取session
    const CMD_GET_SESSION_BY_CLIENT_ID = 203;

    // 发给gateway，覆盖session
    const CMD_SET_SESSION = 204;

    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;

    // 通知gateway在send时不调用协议encode方法，在广播组播时提升性能
    const FLAG_NOT_CALL_ENCODE = 0x02;

    /**
     * 包头长度
     *
     * @var int
     */
    const HEAD_LEN = 28;

    public static $empty = array(
        'cmd'           => 0,
        'local_ip'      => 0,
        'local_port'    => 0,
        'client_ip'     => 0,
        'client_port'   => 0,
        'connection_id' => 0,
        'flag'          => 0,
        'gateway_port'  => 0,
        'ext_data'      => '',
        'body'          => '',
    );

    /**
     * 返回包长度
     *
     * @param string $buffer
     * @return int return current package length
     */
    public static function input($buffer)
    {
        if (strlen($buffer) < self::HEAD_LEN) {
            return 0;
        }

        $data = unpack("Npack_len", $buffer);
        return $data['pack_len'];
    }

    /**
     * 获取整个包的 buffer
     *
     * @param mixed $data
     * @return string
     */
    public static function encode($data)
    {
        $flag = (int)is_scalar($data['body']);
        if (!$flag) {
            $data['body'] = serialize($data['body']);
        }
        $data['flag'] |= $flag;
        $ext_len      = strlen($data['ext_data']);
        $package_len  = self::HEAD_LEN + $ext_len + strlen($data['body']);
        return pack("NCNnNnNCnN", $package_len,
            $data['cmd'], $data['local_ip'],
            $data['local_port'], $data['client_ip'],
            $data['client_port'], $data['connection_id'],
            $data['flag'], $data['gateway_port'],
            $ext_len) . $data['ext_data'] . $data['body'];
    }

    /**
     * 从二进制数据转换为数组
     *
     * @param string $buffer
     * @return array
     */
    public static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nconnection_id/Cflag/ngateway_port/Next_len",
            $buffer);
        if ($data['ext_len'] > 0) {
            $data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
            if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
                $data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
            } else {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
            }
        } else {
            $data['ext_data'] = '';
            if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
                $data['body'] = substr($buffer, self::HEAD_LEN);
            } else {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
            }
        }
        return $data;
    }
}
