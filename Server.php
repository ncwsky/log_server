#!/usr/bin/env php
<?php
//declare(strict_types=1);
require __DIR__ . '/GetOpt.php';
//解析命令参数
GetOpt::parse('hasc:u:t:', ['help', 'all', 'swoole', 'config:', 'udp:', 'tcp:']);
//处理命令参数
$config = GetOpt::val('c', 'config', __DIR__ . '/server.conf.php');
$isSwoole = GetOpt::has('s', 'swoole');
$port = GetOpt::val('p', 'port', '57011');

if (GetOpt::has('h', 'help')) {
    echo 'Usage: php Server.php OPTION [restart|stop]
   or: Server.php OPTION [restart|stop]

   -h --help
   -c --config  配置文件 默认为当前下的 server.conf.php 优先使用配置文件
   -p --port    ws port
   --swoole     swolle运行',PHP_EOL;
    exit(0);
}

$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行 不设置此项使用相对路径运行时 会加载了不相应的引入文件
file_exists($config) && require $config;
defined('VENDOR_DIR')          || define('VENDOR_DIR', '/vendor');
defined('DATA_SERVER_WS_PORT') || define('DATA_SERVER_WS_PORT', $port); #ws服务端口 不建议使用默认值 建议重置
defined('GLOBAL_SWOOLE')       || define('GLOBAL_SWOOLE', $isSwoole); #是否swoole环境
defined('AUTOLOAD')            || define('AUTOLOAD', __DIR__ . VENDOR_DIR . '/autoload.php'); #自动载入

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
            'log_file' => __DIR__ . '/server.log', //日志文件
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
            'stdoutFile' => __DIR__ . '/server.log', //终端输出
            'pidFile' => __DIR__ . '/workeman.pid',
            'logFile' => __DIR__ . '/server.log', //日志文件
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
    'worker_load'=> defined('WORKER_LOAD') ? WORKER_LOAD : __DIR__ . VENDOR_DIR . '/myphps/myphp/base.php',
    'event' => $event,
]);


if(GLOBAL_SWOOLE){
    $srv = new SwooleSrv($serverConf);
}else{
    $srv = new WorkerManSrv($serverConf);
}
$srv->run($argv);