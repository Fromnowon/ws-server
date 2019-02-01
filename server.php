<?php

use Workerman\Worker;
use Workerman\Lib\Timer;

// 心跳间隔
define('HEARTBEAT_TIME', 55);

require_once __DIR__ . '/Workerman-master/Autoloader.php';

$role_arr = array();//身份集合
$num_arr = array();//序号集合

// 注意：这里与上个例子不同，使用的是websocket协议
$ws_worker = new Worker("websocket://0.0.0.0:2000");

// 启动4个进程对外提供服务
$ws_worker->count = 4;

$ws_worker->onWorkerStart = function ($worker) {
    Timer::add(1, function () use ($worker) {
        $time_now = time();
        foreach ($worker->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME) {
                $connection->close();
            }
        }
    });
};


// 当收到客户端发来的数据后返回hello $data给客户端
$ws_worker->onMessage = function ($connection, $data) {
    // 给connection临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
    $connection->lastMessageTime = time();
    $data = json_decode($data, true);
    //收====0：心跳包 1：图片数据 2：身份确认
    //发====10：向展示端推送图片 11：同步选手名单
    switch ($data['code']) {
        case 1:
            //向展示端推送图片
            sendHandler('square', 10, $data['data']);
            break;
        case 2:
            global $role_arr, $num_arr;
            //处理参赛者身份
            /*
            [
                '192.168.1.2':
                    ['role':'competitor','num':1],
                '192.168.1.3':
                    ['role':'square','num':null]
                ...
            ]
            */
            //role_arr变动处之一
            if (in_array($data['data']['num'], $num_arr)) {
                //序号冲突
                $connection->send(msgHandler(2, 'conflict'));
            } else {
                $role_arr[$connection->getRemoteIp()] = array('role' => $data['data']['role'], 'num' => $data['data']['num']);
                if (is_numeric($data['data']['num'])) {
                    array_push($num_arr, $data['data']['num']);
                }
                $connection->send(msgHandler(2, 'ok'));
                print_r($role_arr);
                print_r($num_arr);
                //向展示端同步名单
                sendHandler('square', 11, $role_arr);
            }
            break;
    }
};
$ws_worker->onConnect = function ($connection) {
    global $ws_worker;
    echo $connection->getRemoteIp() . '建立连接；当前连接数：' . count($ws_worker->connections) . "\n";
};
$ws_worker->onClose = function ($connection) {
    global $role_arr, $num_arr;
    //role_arr变动处之一
    $ip = $connection->getRemoteIp();
    unset($num_arr[array_search($role_arr[$ip]['num'], $num_arr)]);
    unset($role_arr[$ip]);
    echo $ip . '断开连接' . "\n";
};
// 运行worker
Worker::runAll();

function msgHandler($code, $data)
{
    //统一处理消息格式
    //[0：心跳包]
    return json_encode(array('code' => $code, 'data' => $data));
}

//针对不同身份推送消息
function sendHandler($role, $code, $data)
{
    global $ws_worker, $role_arr;
    foreach ($ws_worker->connections as $client) {
        if ($role_arr[$client->getRemoteIp()]['role'] === $role) {
            //只发送给展示端
            $client->send(msgHandler($code, $data));
        }
    }
}