#!/usr/bin/env php
<?php
//declare(strict_types=1);
require __DIR__ . '/GetOpt.php';
//解析命令参数
GetOpt::parse('hasc:u:t:', ['help', 'all', 'swoole', 'config:', 'udp:', 'tcp:']);
//处理命令参数
$config = GetOpt::val('c', 'config', __DIR__ . '/client.conf.php');
$isSwoole = GetOpt::has('s', 'swoole');
$udpPort = GetOpt::val('u', 'udp', '55011');
$tcpPort = GetOpt::val('t', 'tcp', $udpPort);
$isAll = GetOpt::has('a', 'all');

if (GetOpt::has('h', 'help')) {
    echo 'Usage: php Client.php OPTION [restart|stop]
   or: Client.php OPTION [restart|stop]

   -h --help
   -c --config  配置文件 默认为当前下的 client.conf.php 优先使用配置文件
   -u --udp     udp port
   -t --tcp     tcp port 未配置时使用udp端口
   -a --all     监听0.0.0.0
   --swoole     swolle运行',PHP_EOL;
    exit(0);
}
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行 不设置此项使用相对路径运行时 会加载了不相应的引入文件
file_exists($config) && require $config;
defined('VENDOR_DIR')    || define('VENDOR_DIR', '/vendor');
defined('DATA_UDP_PORT') || define('DATA_UDP_PORT', $udpPort); #UDP服务端口 不建议使用默认值 建议重置
defined('DATA_TCP_PORT') || define('DATA_TCP_PORT', $tcpPort); #TCP服务端口
defined('GLOBAL_SWOOLE') || define('GLOBAL_SWOOLE', $isSwoole); #是否swoole环境
defined('DATA_LISTEN_IP')|| define('DATA_LISTEN_IP', $isAll ? '0.0.0.0' : '127.0.0.1'); #监听地址
defined('READ_LISTEN_IP')|| define('READ_LISTEN_IP', '0.0.0.0'); #终端数据读取监听地址
defined('REPORT_IP_KEY') || define('REPORT_IP_KEY', 'REPORT_IP'); #报告ip指令
defined('AUTOLOAD')      || define('AUTOLOAD', __DIR__ . VENDOR_DIR. '/autoload.php'); #自动载入
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
                    //'socket_buffer_size' => 1 * 1024 * 1024, //1M 客户端连接的缓存区长度 默认2M
                    //'buffer_output_size' => 1 * 1024 * 1024, //1M 发送输出缓存区内存长度 默认2M

                    //结束符
                    #'open_eof_check' => true, //打开EOF检测 可能会同时收到多个包 需要拆分
                    #'package_eof' => "\n", //设置EOF

                    'open_length_check' => true,
                    'package_length_func' => function ($buffer) { //自定义解析长度
                        if (\strlen($buffer) < 6) {
                            return 0;
                        }
                        $unpack_data = \unpack('Cnull/Ntotal_length/Cstart', $buffer);
                        if ($unpack_data['null'] !== 0x00 || $unpack_data['start'] !== 0x02) {
                            return -1; //数据错误，底层会自动关闭连接
                        }
                        return $unpack_data['total_length'];
                    }
                ],
                'event' => [
                    'onReceive' => function ($server, $fd, $reactor_id, $data) {
                        //定长
                        $data = (array)json_decode(substr($data, 6), true);
                        $result = LogWorkerClient::getHandle($data); #获取数据处理
                        $server->send($fd, pack('CNC', 0x00, 6 + strlen($result), 0x02) . $result);
                        return;

                        //换行符
                        $data = rtrim($data, "\r\n"); //结束符
                        if (strpos($data, "\n")) {
                            $multiData = explode("\n", $data);
                            foreach ($multiData as $data) {
                                $data = (array)json_decode($data, true);
                                $result = LogWorkerClient::getHandle($data); #获取数据处理
                                $server->send($fd, $result."\n");
                            }
                            return;
                        }

                        $data = (array)json_decode($data, true);
                        $result = LogWorkerClient::getHandle($data); #获取数据处理
                        $server->send($fd, $result."\n");
                    },
                ]
            ],
        ],
        'setting' => [
            'worker_num' => 1,
            'pid_file' => __DIR__ . '/client2.pid',
            'log_file' => __DIR__ . '/client.log', //日志文件
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
                    //'protocol' => '\Workerman\Protocols\Text',
                    'protocol' => 'LogPackN2', //'\Workerman\Protocols\Frame',
                ],
                'event' => [
                    'onMessage' => function (\Workerman\Connection\ConnectionInterface $connection, $data) {
                        $data = (array)json_decode($data, true);
                        $connection->send(LogWorkerClient::getHandle($data)); //发送处理结果
                    },
                ]
            ],
        ],
        'setting' => [
            'count' => 1,
            'stdoutFile' => __DIR__ . '/client.log', //终端输出
            'pidFile' => __DIR__ . '/client1.pid',
            'logFile' => __DIR__ . '/client.log', //日志文件
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
            if ($data['msg'] === REPORT_IP_KEY && REPORT_IP_KEY!=='') {
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
            if ($data['msg'] === REPORT_IP_KEY && REPORT_IP_KEY!=='') {
                $server->send('OK:' . DATA_TCP_PORT);
                return;
            }
            $data['ip'] = $client_info['address'];

            LogWorkerClient::handle($data); #日志数据处理
        },
    ],
    // 进程内加载的文件
    'worker_load' => defined('WORKER_LOAD') ? WORKER_LOAD : __DIR__ . VENDOR_DIR . '/myphps/myphp/base.php',
]);

if (GLOBAL_SWOOLE) {
    $srv = new SwooleSrv($clientConf, SWOOLE_BASE);
} else {
    $srv = new WorkerManSrv($clientConf);
}
$srv->run($argv);