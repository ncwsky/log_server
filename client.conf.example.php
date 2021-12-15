<?php
#define('GLOBAL_SWOOLE', 0); //是否swoole环境
#define('DATA_UDP_PORT', 55011); #UDP服务端口 不建议使用默认值 建议重置
#define('DATA_TCP_PORT', 55011); #TCP服务端口
#define('DATA_LISTEN_IP', '127.0.0.1'); #监听地址
#define('READ_LISTEN_IP', '0.0.0.0'); #终端数据读取监听地址
#define('REPORT_IP_KEY', 'REPORT_IP'); #报告ip指令 如需要广播获取ip请设置值 如 REPORT_IP

#define('DATA_WRITE_TIME_TICK', 30); #数据定时落地时间 30秒
#define('DATA_CLEAR_TIME_TICK', 86400); #数据定时清理时间 最大86400
#define('DATA_EXPIRED_TIME', 2678400); #数据过期时间 31天
#define('DATA_MAX_BUFFER_SIZE', 1024000); #最大日志buffer，大于这个值就写磁盘 1M
#define('DATA_PUSH_ADDRESS', '127.0.0.1:57011'); #汇总数据推送地址
#define('DATA_PUSH_TIME_TICK', 1); #汇总数据定时推送数据时间

#define('DATA_SQLITE', 1); //使用sqlite记录接口统计
#define('VENDOR_DIR', '/vendor'); //指定vendor的相对目录
#define('AUTOLOAD', __DIR__ . '/vendor/autoload.php'); #自动载入
#define('WORKER_LOAD', __DIR__ . '/vendor/myphps/myphp/base.php'); #worker进程初始时载入

//日志时间格式匹配表达式 以下示例为默认的
/*
$logTimePattern = [
    // 2021-12-02 20:17:14 2021/07/09 21:22:28
    '2021-12-02 20:17:14'=>'^\d{4}[-\/]\d{2}[-/]\d{2} \d{2}:\d{2}:\d{2}',
    // [09/Jul/2021:16:23:51 +0800]
    '09/Jul/2021:16:23:51 +0800'=>'\d{2}\/\w+\/\d{4}:\d{2}:\d{2}:\d{2} [\+-]\d+',
]
*/
$logTimePattern = [];