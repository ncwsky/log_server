#!/usr/bin/env php
<?php
//declare(strict_types=1);
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行 不设置此项使用相对路径后台运行时（ROOT会是相对路径）会加载了不相应的引入文件
file_exists(__DIR__ . '/client.conf.php') && require __DIR__ . '/client.conf.php';
defined('DATA_UDP_PORT') || define('DATA_UDP_PORT', 11024); #UDP服务端口
defined('DATA_TCP_PORT') || define('DATA_TCP_PORT', 11024); #TCP服务端口
defined('GLOBAL_SWOOLE') || define('GLOBAL_SWOOLE', 0); #是否swoole环境
defined('DATA_LISTEN_IP')|| define('DATA_LISTEN_IP', '127.0.0.1'); #监听地址
defined('READ_LISTEN_IP')|| define('READ_LISTEN_IP', '0.0.0.0'); #终端数据读取监听地址
defined('AUTOLOAD')      || define('AUTOLOAD', __DIR__ . '/../vendor/autoload.php'); #自动载入
if (!isset($logTimePattern)) $logTimePattern = [];

require AUTOLOAD;

$clientConf = [];
if (GLOBAL_SWOOLE) {
    $clientConf = [
        'listen' => [
            'tcp' => [ # 终端数据读取服务
                'ip' => READ_LISTEN_IP,
                'port' => DATA_TCP_PORT,
                'setting' => [
                    //结束符
                    'open_eof_check' => true, //打开EOF检测 可能会同时收到多个包 需要拆分
                    'package_eof' => "\n", //设置EOF
                    'socket_buffer_size' => 1 * 1024 * 1024, //1M 客户端连接的缓存区长度
                    'buffer_output_size' => 1 * 1024 * 1024, //1M 发送输出缓存区内存长度
                ],
                'event' => [
                    'onReceive' => function ($server, $fd, $reactor_id, $data) {
                        $data = rtrim($data, "\r\n"); //结束符
                        if (strpos($data, "\n")) {
                            $multiData = explode("\n", $data);
                            foreach ($multiData as $data) {
                                $data = (array)json_decode($data, true);
                                $result = LogWorkerClient::getHandle($data); #获取数据处理
                                if (is_string($result)) $server->send($fd, $result."\n");
                            }
                            return;
                        }

                        $data = (array)json_decode($data, true);
                        $result = LogWorkerClient::getHandle($data); #获取数据处理
                        if (is_string($result) && $result!=='') $server->send($fd, $result."\n");
                    },
                ]
            ],
        ],
        'setting' => [
            'worker_num' => 1,
            'pid_file' => __DIR__ . '/client2.pid',
            'log_file' => __DIR__ . '/client2.log', //日志文件
            'log_level' => 0,
        ]
    ];
} else {
    $clientConf = [
        'listen' => [
            'tcp' => [ # 终端数据读取服务
                'ip' => READ_LISTEN_IP,
                'port' => DATA_TCP_PORT,
                'setting' => [
                    'protocol' => '\Workerman\Protocols\Text',
                ],
                'event' => [
                    'onMessage' => function (\Workerman\Connection\ConnectionInterface $connection, $data) {
                        $data = (array)json_decode($data, true);
                        $result = LogWorkerClient::getHandle($data); #获取数据处理
                        if (is_string($result)) $connection->send($result);
                    },
                ]
            ],
        ],
        'setting' => [
            'count' => 1,
            'stdoutFile' => __DIR__ . '/stdout1.log', //终端输出
            'pidFile' => __DIR__ . '/client1.pid',
            'logFile' => __DIR__ . '/client1.log', //日志文件
            'protocol' => 'LogPackage',
        ]
    ];
}

$clientConf = array_merge($clientConf, [
    // 主服务-数据上报
    'name' => 'ReportClient', //服务名
    'ver' => '1.0.0',
    'ip' => DATA_LISTEN_IP, //监听地址
    'port' => DATA_UDP_PORT, //监听地址
    'type' => 'udp', //类型[http tcp websocket] 可通过修改createServer方法自定义服务创建
    'event' => [ //udp服务的事件处理
        'onWorkerStart' => function ($worker, $worker_id) use ($logTimePattern) {
            echo 'worker start', PHP_EOL;
            $logTimePattern && LogWorkerClient::$logTimePattern = array_merge(LogWorkerClient::$logTimePattern, $logTimePattern);
            LogWorkerClient::clientStart($worker, $worker_id);
        },
        'onWorkerStop' => function ($worker, $worker_id) {
            echo 'worker stop',PHP_EOL;
            LogWorkerClient::clientStop($worker, $worker_id);
        },
        'onMessage' => function (\Workerman\Connection\ConnectionInterface $connection, $data) { //workerman
            if (!is_array($data)) return;
            #echo $data['msg'],PHP_EOL;
            if ($data['msg'] == 'REPORT_IP') {
                $connection->send('OK:' . DATA_TCP_PORT);
                return;
            }
            $data['ip'] = $connection->getRemoteIp();

            LogWorkerClient::handle($data); #日志数据处理
        },
        'onPacket' => function ($server, $data, $client_info) { //swoole
            $data = LogPackage::decode($data);

            if (!is_array($data)) return;
            #echo $data['msg'],PHP_EOL;
            if ($data['msg'] == 'REPORT_IP') {
                $server->send('OK:' . DATA_TCP_PORT);
                return;
            }
            $data['ip'] = $client_info['address'];

            LogWorkerClient::handle($data); #日志数据处理
        },
    ],
    // 进程内加载的文件
    'worker_load' => defined('WORKER_LOAD') ? WORKER_LOAD : __DIR__ . '/../vendor/myphps/myphp/base.php',
]);

if (GLOBAL_SWOOLE) {
    $srv = new SwooleSrv($clientConf, SWOOLE_BASE);
} else {
    $srv = new WorkerManSrv($clientConf);
}
$srv->run($argv);