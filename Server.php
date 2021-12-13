#!/usr/bin/env php
<?php
declare(strict_types=1);
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行 不设置此项使用相对路径后台运行时（ROOT会是相对路径）会加载了不相应的引入文件
file_exists(__DIR__ . '/server.conf.php') && require __DIR__ . '/server.conf.php';
defined('DATA_SERVER_WS_PORT')   || define('DATA_SERVER_WS_PORT', 7011); #ws服务端口
defined('GLOBAL_SWOOLE')         || define('GLOBAL_SWOOLE', 0); #是否swoole环境
defined('AUTOLOAD')              || define('AUTOLOAD', __DIR__ . '/../vendor/autoload.php'); #自动载入

require AUTOLOAD;

$serverConf = [];
$event = [
    'onWorkerStart' => function ($worker, $worker_id) {
        echo 'tcp worker start', PHP_EOL;
        LogWorkerServer::serverStart($worker, $worker_id);
    },
    'onWorkerStop' => function ($worker, $worker_id) {
        echo 'tcp worker stop', PHP_EOL;
        LogWorkerServer::serverStop($worker, $worker_id);
    },
];
if (GLOBAL_SWOOLE) {
    $serverConf = [
        'setting' => [
            'worker_num' => 1,
            'pid_file' => __DIR__ . '/swoole.pid',
            'log_file' => __DIR__ . '/swoole.log', //日志文件
            'log_level' => 0,
            'reload_async' => true, //异步安全重启特性 Worker进程会等待异步事件完成后再退出
            //'user' => 'www-data', //设置worker/task子进程的进程用户 提升服务器程序的安全性
        ]
    ];

    $event['onMessage'] = function ($server, $frame) {
        $result = LogWorkerServer::cmd($frame->data, $frame->fd); //汇总实时数据处理
        if (is_string($result)) $server->push($frame->fd, $result);
    };
    $event['onClose'] = function ($server, $fd, $reactorId) {
        LogWorkerServer::leavePushGroup($fd);
    };
} else {
    $serverConf = [
        'setting' => [
            'count' => 1,
            'stdoutFile' => __DIR__ . '/stdout.log', //终端输出
            'pidFile' => __DIR__ . '/workeman.pid',
            'logFile' => __DIR__ . '/workeman.log', //日志文件
            # 'user' => 'www-data', //设置worker/task子进程的进程用户 提升服务器程序的安全性
        ]
    ];
    $event['onMessage'] = function (\Workerman\Connection\ConnectionInterface $connection, $data) {
        $result = LogWorkerServer::cmd($data, $connection->id); //汇总实时数据处理
        if (is_string($result)) $connection->send($result);
    };
    $event['onClose'] = function (\Workerman\Connection\ConnectionInterface $connection) {
        LogWorkerServer::leavePushGroup($connection->id);
    };
}

$serverConf = array_merge($serverConf, [
    // 主服务-http数据服务端
    'name' => 'ReportServer', //单进程
    'ver' => '1.0.0',
    'ip' => '0.0.0.0', //监听地址
    'port' => DATA_SERVER_WS_PORT, //监听地址
    'type' => 'websocket', //类型[http tcp websocket] 可通过修改createServer方法自定义服务创建
    // 进程内加载的文件
    'worker_load'=> defined('WORKER_LOAD') ? WORKER_LOAD : __DIR__ . '/../vendor/myphps/myphp/base.php',
    'event' => $event,
]);


if(GLOBAL_SWOOLE){
    $srv = new SwooleSrv($serverConf);
}else{
    $srv = new WorkerManSrv($serverConf);
}
$srv->run($argv);