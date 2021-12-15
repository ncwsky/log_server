<?php
#define('GLOBAL_SWOOLE', 1); #是否swoole环境  默认workerman
#define('DATA_SERVER_WS_PORT', 57011); #ws 实时汇总服务 不建议使用默认值 建议重置

#define('DATA_WRITE_TIME_TICK', 30); #数据定时落地时间
#define('DATA_CLEAR_TIME_TICK', 86400); #数据定时清理时间
#define('DATA_EXPIRED_TIME', 1296000); #数据过期时间 15天
#define('DATA_REALTIME_KEEP', 5); #实时数据保留时间 建议1-10
#define('VENDOR_DIR', '/../vendor'); //指定vendor的相对目录
#define('AUTOLOAD', __DIR__ . '/vendor/autoload.php'); #自动载入
#define('WORKER_LOAD', __DIR__ . '/vendor/myphps/myphp/base.php'); #worker进程初始时载入