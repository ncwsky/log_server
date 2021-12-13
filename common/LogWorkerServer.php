<?php
defined('DATA_REALTIME_KEEP')   || define('DATA_REALTIME_KEEP', 5); #实时数据保留时间 建议1-10
defined('DATA_REALTIME_DIR')    || define('DATA_REALTIME_DIR', __DIR__ . '/../data/realtime'); #放实时数据的目录

/**
 * Class LogWorkerServer 日志汇总数据处理
 */
class LogWorkerServer extends LogWorkerAbstract
{
    /** 推送接收成员组
     * @var array
     */
    public static $push_group = [];

    public static $push_key_list = [];

    /** 服务端实时统计数据
     * @var array
     */
    protected static $serverRealtimeData = [];

    /** 服务端进程启动时的处理
     * @param Worker2|swoole_server $worker
     * @param $worker_id
     */
    public static function serverStart($worker, $worker_id)
    {
        // 实时汇总数据存放目录
        umask(0);
        if (!is_dir(DATA_REALTIME_DIR)) {
            mkdir(DATA_REALTIME_DIR, 0755, true);
        }

        // 定时保存实时统计数据
        $worker->tick(DATA_WRITE_TIME_TICK * 1000, function () {
            self::writeRealtimeToDisk();
        });
        if ($worker_id == 0) { #防止多进程时重复操作清理
            // 定时清理不用的统计数据
            $worker->tick((DATA_CLEAR_TIME_TICK > 86400 ? 86400 : DATA_CLEAR_TIME_TICK) * 1000, function () {
                self::clear(DATA_REALTIME_DIR, DATA_EXPIRED_TIME);
            });
        }
        // 定时推送数据到ws终端
        $worker->tick(1000, function () use ($worker) { # DATA_REALTIME_KEEP
            if(!self::$push_group) return;
            $sendList = [];
            foreach (self::$push_key_list as $key_name => $num) {
                list($key, $sec) = explode(':', $key_name);
                $sendList[$key_name] = self::getRealTime($key, (int)$sec);
            }
            if(GLOBAL_SWOOLE){
                foreach (self::$push_group as $fd => $key_name) {
                    $worker->push($fd, toJson($sendList[$key_name]));
                }
            }else{
                foreach (self::$push_group as $fd => $key_name) {
                    $worker->connections[$fd]->send(toJson($sendList[$key_name]));
                }
            }
        });
    }

    /** 终端数据进程结束时的处理
     * @param Worker2|swoole_server $worker
     * @param $worker_id
     */
    public static function serverStop($worker, $worker_id)
    {
        #进程结束时把缓存的实时汇总数据写入磁盘
        self::writeRealtimeToDisk(true);
    }

    /**
     * 将实时汇总数据写入磁盘
     * @param $writeAll
     */
    public static function writeRealtimeToDisk($writeAll = false)
    {
        $dayTime = mktime(0, 0, 0);
        //$metric = self::$typeName[self::TYPE_METRIC];
        $srvTime = time();
        $latestTime = $srvTime - DATA_REALTIME_KEEP; #只对x秒前的数据写入
        // 循环将每个时间的统计数据写入磁盘
        foreach (self::$serverRealtimeData as $time => $pathData) {
            if (!$writeAll && $time >= $latestTime) continue;

            $sec = $time - $dayTime;
            if ($sec < 0) { // 起始时间不一致
                $sec = 0 - $sec;
                $sec = 86400 - ($sec > 86400 ? $sec % 86400 : $sec);
            }
            $ymd = date('Y-m-d', $time);
            $minute = intval($sec / 600); # 每10分钟内容为一个文件
            // 循环将每个ip的统计数据写入磁盘
            foreach ($pathData as $path => $ipData) {
                $arr = explode('/', $path, 3); #仅生成3级 type/module/name
                $type = $arr[0];
                $typeName = self::$typeName[$type];
                $module = $arr[1];
                $name = str_replace('/', '+', $arr[2]);
                // 文件夹不存在则创建一个
                $dir = DATA_REALTIME_DIR . '/' . $typeName .'/'. $module.'/'. $name;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                $flags = FILE_APPEND | LOCK_EX;
                $file = $dir . '/' . $minute . '_' . $ymd;
                $tmpData = '';
                $n = 0;
                // 依次写入磁盘
                if ($type == self::TYPE_METRIC) {
                    foreach ($ipData as $ip => $item) {
                        foreach ($item as $k => $data) {
                            $n++;
                            $tmpData .= $ip . "\t" . $time . "\t" . $data . "\n";
                            if ($n == 100) { #每n行写一次
                                file_put_contents($file, $tmpData, $flags);
                                $tmpData = '';
                                $n = 0;
                            }
                        }
                    }
                } else {
                    foreach ($ipData as $ip => $item) {
                        $n++;
                        $tmpData .= $ip . "\t" . $time . "\t" . $item[0] . "\t" . $item[1] . "\n"; //0:ok 1:fail
                        if ($n == 100) { #每n行写一次
                            file_put_contents($file, $tmpData, $flags);
                            $tmpData = '';
                            $n = 0;
                        }
                    }
                }
                if ($n > 0) {
                    file_put_contents($file, $tmpData, $flags);
                    $tmpData = '';
                }
            }

            unset(self::$serverRealtimeData[$time]);
        }
    }

    /** 接收统计数据处理
     * @param array|string $data
     * @param $fd
     */
    public static function cmd($data, $fd)
    {
        if(is_string($data)) $data = (array)json_decode($data, true);
        $cmd = isset($data['cmd']) ? $data['cmd'] : '';
        $buffer = true;
        switch ($cmd) {
            case 'get_real_time':
                $key = isset($data['key']) ? self::filter($data['key'],1) : '';
                $sec = isset($data['sec']) ? (int)$data['sec'] : DATA_REALTIME_KEEP-1; #获取x秒之前的数据
                $buffer = toJson(self::getRealTime($key, $sec));
                break;
            case 'set_real_time':
                self::setRealtime($data['data']); //汇总实时数据处理
                break;
            case 'join_push_group':
                $key = isset($data['key']) ? self::filter($data['key'],1) : '';
                $sec = isset($data['sec']) ? (int)$data['sec'] : DATA_REALTIME_KEEP-1; #获取x秒之前的数据
                self::joinPushGroup($fd, $key, $sec);
                $buffer = 'ok';
                break;
            case 'leave_push_group':
                unset(self::$push_group[$fd]);
                break;
        }
        return $buffer;
    }

    /** 加入推送组处理
     * @param $fd
     * @param $key
     * @param $sec
     */
    public static function joinPushGroup($fd, $key, $sec){
        $key_name = $key.':'.$sec;
        self::$push_group[$fd] = $key_name;
        if(!isset(self::$push_key_list[$key_name])){
            self::$push_key_list[$key_name] = 0;
        }
        self::$push_key_list[$key_name] += 1;
    }
    /** 离开推送组处理
     * @param $fd
     */
    public static function leavePushGroup($fd){
        if(!isset(self::$push_group[$fd])) return;

        $key_name = self::$push_group[$fd];
        self::$push_key_list[$key_name] -= 1;
        if(self::$push_key_list[$key_name]==0){
            unset(self::$push_key_list[$key_name]);
        }
        unset(self::$push_group[$fd]);
    }
    /** 服务端实时数据汇总
     * @param array $data
     */
    public static function setRealtime($data)
    {
        //$metric = self::$typeName[self::TYPE_METRIC];
        //$srvTime = time();
        foreach ($data as $time => $pathData) {
            if (!isset(self::$serverRealtimeData[$time])) {
                self::$serverRealtimeData[$time] = [];
            }
            foreach ($pathData as $path => $ipData) {
                $path = self::filter($path, 2);
                $type = strstr($path, '/', true);
                if(!isset(self::$typeName[$type])) continue; // 非有效的type

                #$arr = explode('/', $path); $type = $arr[0]; $m = $arr[1]; $c = isset($arr[2]) ? $arr[2] : ''; $a = isset($arr[3]) ? $arr[3] : '';
                if (!isset(self::$serverRealtimeData[$time][$path])) {
                    self::$serverRealtimeData[$time][$path] = [];
                }
                if ($type == self::TYPE_METRIC) {
                    foreach ($ipData as $ip => $item) {
                        if (!isset(self::$serverRealtimeData[$time][$path][$ip])) {
                            self::$serverRealtimeData[$time][$path][$ip] = [];
                        }
                        self::$serverRealtimeData[$time][$path][$ip] = array_merge(self::$serverRealtimeData[$time][$path][$ip], $item);
                    }
                    continue;
                }
                foreach ($ipData as $ip => $item) {
                    if (!isset(self::$serverRealtimeData[$time][$path][$ip])) {
                        self::$serverRealtimeData[$time][$path][$ip] = [0, 0]; //ok, fail
                    }
                    self::$serverRealtimeData[$time][$path][$ip][0] += $item[0];
                    self::$serverRealtimeData[$time][$path][$ip][1] += $item[1];
                }
            }
        }
    }

    /**
     * @param string $key
     * @param int $sec
     * @return array
     */
    public static function getRealTime($key, $sec)
    {
        $nowTime = time();
        $ret = [];
        if ($key === '') return $ret;

        $type = strstr($key, '/', true);
        if(!in_array($type, ['interface','metric'])) return $ret;

        if ($sec >= DATA_REALTIME_KEEP || $sec <= 0) $sec = DATA_REALTIME_KEEP-1;
        $metric = self::$typeName[self::TYPE_METRIC];
        $isMulti = substr($key, -1) == '*'; // *多个：/xx/*
        $pattern = $isMulti ? substr($key, 0, -1) : $key;
        $startLen = strlen($pattern);

        $time = $nowTime - $sec;
        $ret[$time] = [];
        if (isset(self::$serverRealtimeData[$time])) {
            foreach (self::$serverRealtimeData[$time] as $path => $ipData) {
                if (strpos($path, $pattern) !== 0) continue;
                $k = $pattern;
                if ($isMulti) { // 多个处理
                    $find = strpos($path, '/', $startLen);
                    $k = $find ? substr($path, 0, $find) : $path;
                }

                if (!isset($ret[$time][$k])) {
                    $ret[$time][$k] = [];
                }
                if ($type == $metric) { // 非累加数据
                    foreach ($ipData as $ip => $item) {
                        if (!isset($ret[$time][$k][$ip])) {
                            $ret[$time][$k][$ip] = [];
                        }
                        $ret[$time][$k][$ip] = array_merge($ret[$time][$k][$ip], $item);
                    }
                    continue;
                }
                foreach ($ipData as $ip => $item) {
                    if (!isset($ret[$time][$k][$ip])) {
                        $ret[$time][$k][$ip] = [0, 0]; //ok, fail
                    }
                    $ret[$time][$k][$ip][0] += $item[0];
                    $ret[$time][$k][$ip][1] += $item[1];
                }
            }
        }
        return $ret;
    }
} 
