<?php
// 全局常量定义
defined('DATA_WRITE_TIME_TICK') || define('DATA_WRITE_TIME_TICK', 30); #数据定时落地时间
defined('DATA_CLEAR_TIME_TICK') || define('DATA_CLEAR_TIME_TICK', 86400); #数据定时清理时间
defined('DATA_EXPIRED_TIME')    || define('DATA_EXPIRED_TIME', 2678400); #数据过期时间 31天
defined('DATA_MAX_BUFFER_SIZE') || define('DATA_MAX_BUFFER_SIZE', 1024000); #最大日志buffer，大于这个值就写磁盘 1M
defined('DATA_PUSH_ADDRESS')    || define('DATA_PUSH_ADDRESS', ''); #汇总数据推送地址 如127.0.0.1:7011
defined('DATA_PUSH_TIME_TICK')  || define('DATA_PUSH_TIME_TICK', 1); #汇总数据定时推送数据时间 建议1-10
defined('GLOBAL_SWOOLE')        || define('GLOBAL_SWOOLE', 0); #是否swoole环境
defined('DATA_SQLITE')          || define('DATA_SQLITE', 0); #使用sqlite记录
defined('DATA_SLOW_TIME')       || define('DATA_SLOW_TIME', 0); #慢日志配置 0关闭 单位秒.支持小数位 大于此时间记录到慢日志
/**
 * Class LogWorkerAbstract
 */
abstract class LogWorkerAbstract
{
    /**
     * 统计类型
     */
    const TYPE_INTERFACE_FAIL = 0; #失败[接口]
    const TYPE_INTERFACE_OK = 1; #成功[接口]
    const TYPE_METRIC = 2; #指标
    const TYPE_LOG = 4; #仅日志

    public static $typeName = [
        self::TYPE_INTERFACE_FAIL => 'interface', #失败[接口]
        self::TYPE_INTERFACE_OK => 'interface', #成功[接口]
        self::TYPE_METRIC => 'metric', #指标
        self::TYPE_LOG => 'log', #仅日志
    ];
    /**
     * @var string[] 日志时间匹配正则 [时间格式=>正则表达式, ...]
     */
    public static $logTimePattern = [
        // 2021-12-02 20:17:14 2021/07/09 21:22:28
        '2021-12-02 20:17:14'=>'^\d{4}[-\/]\d{2}[-\/]\d{2} \d{2}:\d{2}:\d{2}',
        // [09/Jul/2021:16:23:51 +0800]
        '09/Jul/2021:16:23:51 +0800'=>'\d{2}\/\w+\/\d{4}:\d{2}:\d{2}:\d{2} [\+-]\d+',
    ];

    public static $logPattern = null;
    /**
     * 获取日志匹配的时间正则
     * @param $line
     * @return bool|string
     */
    public static function getLogPattern($line)
    {
        foreach (static::$logTimePattern as $t => $pattern) {
            if (preg_match('/' . $pattern . '/', $line, $match)) {
                $tmp_time = strtotime($match[0]);
                if ($tmp_time) return $pattern;
            }
        }
        return false;
    }
    /** 过滤
     * @param $name
     * @param int $ways
     * @return string|string[]
     */
    public static function filter($name, $ways=0)
    {
        $name = trim($name);
        if($name==='') return $name;
        if($ways==1){
            return str_replace(["\t", '\\', ':', '?', '"', '<', '>', '|', '..'], '', $name); //排除 /*
        }elseif($ways==2){
            return str_replace(["\t", '\\', ':', '*', '?', '"', '<', '>', '|', '..'], '', $name); //排除 /
        }else{
            return str_replace(["\t", '\\', '/', ':', '*', '?', '"', '<', '>', '|', '..'], '', $name);
        }
    }

    /**
     * 清除磁盘数据
     * @param string $file  目录或文件
     * @param int $exp_time
     * @param int $time
     * @param bool $clear_dir
     */
    public static function clear($file = null, $exp_time = 86400, $time = 0, $clear_dir=false)
    {
        if ($time == 0) $time = time();
        if (is_file($file)) {
            $mtime = filemtime($file);
            if (!$mtime) {
                Log::NOTICE("filemtime $file fail");
                return;
            }
            if ($time - $mtime > $exp_time) {
                unlink($file);
            }
            return;
        }
        $files = glob($file . "/*");
        if (count($files) == 0) {
            if($clear_dir) { //删除空目录
                rmdir($file);
                clearstatcache();
            }
        } else {
            foreach ($files as $file_name) {
                self::clear($file_name, $exp_time, $time, true);
            }
        }
    }
} 
