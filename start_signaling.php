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

/**
 *  基于workerman 的webrtc信令服务器(signaling server)
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
use Workerman\Worker;

// 订阅主题和连接的对应关系
$subject_connnection_map = array();
if (isset($SSL_CONTEXT)) {
    // websocket监听8877端口
    $worker = new Worker('websocket://0.0.0.0:8877', $SSL_CONTEXT);
    $worker->transport = 'ssl';
} else {
    // websocket监听8877端口
    $worker = new Worker('websocket://0.0.0.0:8877');
}

// 进程数只能设置为1，避免多个连接连连到不同进程
// 不用担心性能问题，作为Signaling Server，workerman一个进程就足够了
$worker->count = 1;
// 进程名字
$worker->name = 'Signaling Server';
// 连接上来时设置个subjects属性，用来保存当前连接
$worker->onConnect = function ($connection){
    $connection->subjects = array();
};
// 当客户端发来数据时
$worker->onMessage = function($connection, $data)
{
    $data = json_decode($data, true);
    switch ($data['cmd']) {
        // 订阅主题
        case 'subscribe':
            $subject = $data['subject'];
            subscribe($subject, $connection);
            break;
        // 向某个主题发布消息
        case 'publish':
            $subject = $data['subject'];
            $event = $data['event'];
            $data = $data['data'];
            publish($subject, $event, $data, $connection);
            break;
    }
};

// 客户端连接关闭时把连接从主题映射数组里删除
$worker->onClose = function($connection){
    destry_connection($connection);
};

// 订阅
function subscribe($subject, $connection) {
    global $subject_connnection_map;
    $connection->subjects[$subject] = $subject;
    $subject_connnection_map[$subject][$connection->id] = $connection;
}

// 取消订阅
function unsubscribe($subject, $connection) {
    global $subject_connnection_map;
    unset($subject_connnection_map[$subject][$connection->id]);
}

// 向某个主题发布事件
function publish($subject, $event, $data, $exclude) {
    global $subject_connnection_map;
    if (empty($subject_connnection_map[$subject])) {
        return;
    }
    foreach ($subject_connnection_map[$subject] as $connection) {
        if ($exclude == $connection) {
            continue;
        }
        $connection->send(json_encode(array(
            'cmd'   => 'publish',
            'event' => $event,
            'data'  => $data
        )));
    }
}

// 清理主题映射数组
function destry_connection ($connection) {
    foreach ($connection->subjects as $subject) {
        unsubscribe($subject, $connection);
    }
}

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
