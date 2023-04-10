<?php
defined('DATA_STATISTIC_DIR')   || define('DATA_STATISTIC_DIR', __DIR__ . '/../data/statistic'); #放统计数据的目录
defined('DATA_LOG_DIR')         || define('DATA_LOG_DIR', __DIR__ . '/../data/log'); #存放统计日志的目录

/**
 * Class LogWorkerClient 日志上报数据处理
 */
class LogWorkerClient extends LogWorkerAbstract
{
    const NAME_SLASH = '+'; // name参数里的斜杠转换符
    const ALL_NAME = '--all';
    /**
     * 统计数据
     * path[type/module/controller]=>ip=>['code'=>[xx=>count,xx=>count],'slow'=>xx,'max'=>x,'min'=>xx,suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx]
     * @var array
     */
    protected static $data = [];

    /** 实时统计数据
     * @var array
     */
    protected static $realtimeData = [];

    /**
     * 日志的buffer [module=>string, ...]
     * @var array
     */
    public static $logBuffer = [];
    protected static $logLen = [];

    /**
     * @var Db
     */
    protected static $db = null; //用于释放处理

    /** 终端数据进程启动时的处理
     * @param Worker2|swoole_server $worker
     * @param $worker_id
     * @throws Exception
     */
    public static function clientStart($worker, $worker_id)
    {
        umask(0);
        if (!is_dir(DATA_STATISTIC_DIR)) {
            mkdir(DATA_STATISTIC_DIR, 0755, true);
        }
        if (!is_dir(DATA_LOG_DIR)) {
            mkdir(DATA_LOG_DIR, 0755, true);
        }

        // 定时保存统计数据
        $worker->tick(DATA_WRITE_TIME_TICK * 1000, function () {
            self::writeStatisticsToDisk();
            self::writeLogToDisk();
            #Log::write('DATA_WRITE_TIME_TICK');
        });
        if ($worker_id == 0) { #防止多进程时重复操作清理
            // 定时清理不用的统计数据
            $worker->tick((DATA_CLEAR_TIME_TICK > 86400 ? 86400 : DATA_CLEAR_TIME_TICK) * 1000, function () {
                self::clear(DATA_STATISTIC_DIR, DATA_EXPIRED_TIME);
                self::clear(DATA_LOG_DIR, DATA_EXPIRED_TIME);
            });
        }
        // 与汇总服务建立连接
        if (DATA_PUSH_ADDRESS) {
            $timer_id = 0;
            if (GLOBAL_SWOOLE) {
                // 使用http推送
                $timer_id = $worker->tick(DATA_PUSH_TIME_TICK * 1000, function () {
                    $data = json_encode(['cmd'=>'set_real_time','data'=>self::$realtimeData]);
                    self::$realtimeData = []; #重置
                    // 发送数据
                    Http::doPost('http://' . DATA_PUSH_ADDRESS, $data, 1);
                });
            } else {
                $pushClient = new \Workerman\Connection\AsyncTcpConnection('ws://' . DATA_PUSH_ADDRESS);
                $pushClient->onClose = function (\Workerman\Connection\AsyncTcpConnection $connection) {
                    $connection->reconnect(2); #参见 http://doc.workerman.net/worker/on-error.html
                };
                $pushClient->onConnect = function($connection){
                    #$connection->send(json_encode(['cmd'=>'join_push_group'])); //加入接收推送数据组
                };
                // 执行异步连接
                $pushClient->connect();

                $GLOBALS['pushClient'] = $pushClient;
                // 定时推送实时数据
                $timer_id = $worker->tick(DATA_PUSH_TIME_TICK * 1000, function () use ($pushClient) {
                    $data = json_encode(['cmd'=>'set_real_time','data'=>self::$realtimeData]);
                    self::$realtimeData = []; #重置
                    // 发送数据
                    $pushClient->send($data);
                });
            }
        }
    }

    /** 终端数据进程结束时的处理
     * @param Worker2|swoole_server $worker
     * @param $worker_id
     */
    public static function clientStop($worker, $worker_id)
    {
        #进程结束时把缓存的日志数据写入到磁盘
        self::writeStatisticsToDisk();
        self::writeLogToDisk();

        if (DATA_PUSH_ADDRESS && self::$realtimeData) {
            $data = json_encode(['cmd'=>'set_real_time','data'=>self::$realtimeData]);
            self::$realtimeData = []; #重置
            if (GLOBAL_SWOOLE) {
                // 发送数据
                Http::doPost('http://' . DATA_PUSH_ADDRESS, $data, 3);
            } else {
                // 发送数据
                $GLOBALS['pushClient']->send($data);
            }
        }
    }

    /**
     * 收集统计数据
     * @param string $path
     * @param float $cost_time
     * @param int $success
     * @param string $ip
     * @param int $code
     * @return void
     */
    protected static function collect($path, $cost_time, $success, $ip, $code)
    {
        // 统计相关信息
        if (!isset(self::$data[$path])) {
            self::$data[$path] = [];
        }
        if (!isset(self::$data[$path][$ip])) {
            self::$data[$path][$ip] = ['code'=>[], 'slow'=>0, 'max'=>0, 'min'=>1, 'suc_cost_time' => 0, 'fail_cost_time' => 0, 'suc_count' => 0, 'fail_count' => 0];
        }
        if (!isset(self::$data[$path][$ip]['code'][$code])) {
            self::$data[$path][$ip]['code'][$code] = 0;
        }
        self::$data[$path][$ip]['code'][$code]++;

        if(DATA_SLOW_TIME>0 && $cost_time>DATA_SLOW_TIME) self::$data[$path][$ip]['slow']++;
        if ($cost_time > 0) {
            if (self::$data[$path][$ip]['max'] < $cost_time) self::$data[$path][$ip]['max'] = $cost_time;
            if (self::$data[$path][$ip]['min'] > $cost_time) self::$data[$path][$ip]['min'] = $cost_time;
        }

        if ($success) {
            self::$data[$path][$ip]['suc_cost_time'] += $cost_time;
            self::$data[$path][$ip]['suc_count']++;
        } else {
            self::$data[$path][$ip]['fail_cost_time'] += $cost_time;
            self::$data[$path][$ip]['fail_count']++;
        }
    }

    /** 终端实时数据汇总
     * @param int $time
     * @param $path
     * @param $success
     * @param $ip
     * @param $msg
     * @param bool $is_metric
     */
    protected static function realtime($time, $path, $success, $ip, $msg, $is_metric = false)
    {
        if (!isset(self::$realtimeData[$time])) {
            self::$realtimeData[$time] = [];
        }
        if (!isset(self::$realtimeData[$time][$path])) {
            self::$realtimeData[$time][$path] = [];
        }

        if ($is_metric) { // 追加数据
            if (!isset(self::$realtimeData[$time][$path][$ip])) {
                self::$realtimeData[$time][$path][$ip] = [];
            }
            self::$realtimeData[$time][$path][$ip][] = $msg;
        } else { // 统计数据
            if (!isset(self::$realtimeData[$time][$path][$ip])) {
                self::$realtimeData[$time][$path][$ip] = [0, 0]; //ok, fail
            }
            if ($success) {
                self::$realtimeData[$time][$path][$ip][0]++;
            } else {
                self::$realtimeData[$time][$path][$ip][1]++;
            }
        }
    }

    /**
     * 将统计数据写入磁盘 - 存在进程阻塞
     * @return void
     */
    public static function writeStatisticsToDisk()
    {
        $time = time();
        $date = date('Y-m-d');

        if(DATA_SQLITE){
            foreach (self::$data as $path => $ip_data) {
                list($type, $module, $name) = explode('/', $path, 3);
                $sqlite = DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.' . $module . '.sqlite';
                if(!is_file($sqlite)){
                    copy(__DIR__.'/copy.sqlite', $sqlite);
                }
                $db = self::sqliteDb($sqlite);
                $db->beginTrans();
                //$model = new Model('statistic', $db);
                foreach ($ip_data as $ip => $data) {
                    //$model->setData(
                    $db->add([
                        'path'=>$name,
                        'ip'=>$ip,
                        'time'=>$time,
                        'slow_times'=>$data['slow'],
                        'max_cost_time'=>round($data['max'],6),
                        'min_cost_time'=>round($data['min'],6),
                        'suc_count'=>$data['suc_count'],
                        'suc_cost_time'=>round($data['suc_cost_time'],6),
                        'fail_count'=>$data['fail_count'],
                        'fail_cost_time'=>round($data['fail_cost_time'],6),
                        'code'=>json_encode($data['code'])
                    ],'statistic');
                    //$model->save(null, null, 0);
                }
                $db->commit();
            }
        }else{
            // 循环将每个ip的统计数据写入磁盘
            foreach (self::$data as $path => $ip_data) {
                list($type, $module, $name) = explode('/', $path, 3);
                // 文件夹不存在则创建一个 #仅生成3级 type/module/name
                $dir = DATA_STATISTIC_DIR . '/' . $type . '/' . $module . '/' . str_replace('/', self::NAME_SLASH, $name);
                if (!is_dir($dir)) {
                    //umask(0);
                    mkdir($dir, 0755, true);
                }
                $n = 0;
                $tmpData = '';
                foreach ($ip_data as $ip => $data) {
                    $data['suc_cost_time'] = round($data['suc_cost_time'], 6); #6位小数
                    $data['fail_cost_time'] = round($data['fail_cost_time'], 6);
                    $data['max'] = round($data['max'], 6);
                    $data['min'] = round($data['min'], 6);

                    // 批量写入磁盘
                    $n++;
                    $tmpData .= "$ip\t$time\t{$data['suc_count']}\t{$data['suc_cost_time']}\t{$data['fail_count']}\t{$data['fail_cost_time']}\t" . json_encode($data['code']) . "\t{$data['slow']}\t{$data['max']}\t{$data['min']}\n";
                    if ($n == 100) {
                        file_put_contents($dir . "/" . $date, $tmpData, FILE_APPEND | LOCK_EX);
                        $tmpData = '';
                        $n = 0;
                    }
                }
                if ($n > 0) {
                    file_put_contents($dir . "/" . $date, $tmpData, FILE_APPEND | LOCK_EX);
                    $tmpData = '';
                }
            }
        }
        // 清空统计
        self::$data = [];
    }

    /**
     * 将日志数据写入磁盘 - 存在进程阻塞
     * @return void
     */
    public static function writeLogToDisk()
    {
        // 没有统计数据则返回
        if (empty(self::$logBuffer)) {
            return;
        }

        foreach (self::$logBuffer as $path => $buffer) {
            $dir = DATA_LOG_DIR . '/' . $path;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            // 写入磁盘
            file_put_contents($dir . '/' . date('Y-m-d'), $buffer, FILE_APPEND | LOCK_EX);
        }

        self::$logBuffer = [];
        self::$logLen = [];
    }

    /** 接收统计数据处理
     * @param array $data
     */
    public static function handle($data)
    {
        // 统计类型
        $type = $data['type'];
        if (!isset(self::$typeName[$type])) return;
        // 模块
        $module = $data['module'] = self::filter($data['module']);
        if ($module==='') return;

        if(strpos($data['module'],'@')){ // 有指定ip module@ip
            list($module, $ip) = explode('@', $data['module'], 2);
            $data['ip'] = $ip;
        }

        $data['name'] = self::filter($data['name'], 2); // 层级使用/分隔
        if($data['name']==='') $data['name'] = '--none'; //未指定名称时

        $typeName = self::$typeName[$type];
        $srcPath  = $type . '/' . $module;
        $realPath = $typeName . '/' . $module;
        // 除仅日志
        if ($type != self::TYPE_LOG) {
            if ($data['time_start'] == 0) $data['time_start'] = microtime(true);
            if (strpos($data['time_start'], '.') === false) { # 有精度问题 如1614736648.000039 只会得到整数部分
                $time = (int)$data['time_start'];
                $msec = '0';
            } else {
                list($time, $msec) = explode('.', $data['time_start']);
                $time = (int)$time;
            }
            $cost_time = $data['cost_time'];
            $code = isset($data['code']) ? $data['code'] : 0;
            $ip = isset($data['ip']) ? $data['ip'] : '-';

            $success = $type == self::TYPE_INTERFACE_FAIL ? 0 : 1;
            $is_metric = $type == self::TYPE_METRIC;

            //实时统计
            DATA_PUSH_ADDRESS && self::realtime($time, $srcPath . '/' . $data['name'], $success, $ip, $data['msg'], $is_metric);

            if ($type <= self::TYPE_INTERFACE_OK) {
                // 接口统计
                self::collect($realPath . '/' . $data['name'], $cost_time, $success, $ip, $code);

                // 接口全局统计
                self::collect($realPath . '/' . self::ALL_NAME, $cost_time, $success, $ip, $code);

                if ($type == self::TYPE_INTERFACE_FAIL) { //重置msg 记录失败的日志
                    $data['msg'] = date('Y-m-d H:i:s', $time) . '.' . $msec . "\t{$ip}\t{$data['name']}\tcode:{$code}\tmsg:{$data['msg']}";
                }
            }
        }

        // 接口失败或日志记录
        if ($type == self::TYPE_INTERFACE_FAIL || $type == self::TYPE_LOG) {
            if (!isset(self::$logBuffer[$realPath])) {
                self::$logBuffer[$realPath] = '';
                self::$logLen[$realPath] = 0;
            }
            //统计长度
            if (strpos($data['msg'], "\n") !== false) {
                $data['msg'] = str_replace("\n", "\t", $data['msg']);
            }
            self::$logLen[$realPath] += $data['msg_len'] + 1;
            self::$logBuffer[$realPath] .= $data['msg'] . "\n";

            if (self::$logLen[$realPath] >= DATA_MAX_BUFFER_SIZE) {
                self::writeLogToDisk();
            }
        }
    }

    protected static function preCountParams(&$data){
        if(!isset($data['date'])) $data['date'] = date("Y-m-d");
        $data['start'] = isset($data['start']) ? (int)$data['start'] : 0;
        $data['end'] = isset($data['end']) ? (int)$data['end'] : 0;
        $data['limit'] = isset($data['limit']) ? (int)$data['limit'] : 100;
    }

    /**
     * 获取|处理终端数据
     * @param $data
     * @return bool|false|string
     * @throws Exception
     */
    public static function getHandle($data)
    {
        $cmd = isset($data['cmd']) ? $data['cmd'] : '';
        $type = isset($data['type']) ? self::filter($data['type']) : '';
        $module = isset($data['module']) ? self::filter($data['module']) : '';
        $name = isset($data['name']) ? self::filter($data['name'], 1) : ''; # 排除 /*

        switch ($cmd) {
            case 'get_fail_count': //仅sqlite
                self::preCountParams($data);

                $buffer = json_encode(self::getFailCountSqlite($type, $module, $data['date'], $name, $data['start'], $data['end'], $data['limit']));
                break;
            case 'get_path_count': //仅sqlite
                self::preCountParams($data);

                $buffer = json_encode(self::getPathCountSqlite($type, $module, $data['date'], $name, $data['start'], $data['end'], $data['limit']));
                break;
            case 'get_ip_count':
                self::preCountParams($data);

                if(DATA_SQLITE){
                    $buffer = json_encode(self::getIpCountSqlite($type, $module, $data['date'], $name, $data['start'], $data['end'], $data['limit']));
                }else{
                    $path = '';
                    if ($type != '' && $module != '') {
                        $path = $type . '/' . $module;
                    }
                    if ($path!=='' && $name !== '') {
                        $path .= '/' . str_replace('/', self::NAME_SLASH, $name);
                    }
                    $buffer = json_encode(self::getIpCountFile($path, $data['date'], $data['start'], $data['end'], $data['limit']));
                }

                break;
            case 'get_path':
                $date = isset($data['date']) ? $data['date'] : date("Y-m-d");
                if(DATA_SQLITE){
                    $sqlite = DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.' . $module . '.sqlite';
                    if (!is_file($sqlite)) return '';

                    $db = self::sqliteDb($sqlite);
                    $sql = self::sqliteSql('path,sum(suc_count+fail_count) num', 'path', '', 0, $name);
                    $list = [];
                    $res = $db->query($sql);
                    while($rs=$db->fetch_array($res)){
                        $list[$rs['path']] = $rs['num'];
                    }
                    $buffer = json_encode($list);
                    break;
                }
                $path = '';
                if($type!==''){
                    if($module == ''){
                        $path = $type . '/*';
                    }else{
                        $path = $type . '/' . $module;
                        if ($name === '') {
                            $path .= '/*';
                        }else{
                            $path .= '/' . str_replace('/', self::NAME_SLASH, $name);
                        }
                    }
                }

                $buffer = json_encode(self::getPath($path));
                break;
            case 'get_statistic':
                $date = isset($data['date']) ? $data['date'] : '';
                $minute = isset($data['minute']) ? (int)$data['minute'] : 5; //预处理x分钟数据
                if ($minute < 1 || $minute > 60) $minute = 5;
                $second = $minute * 60;
                if($name==='') $name = self::ALL_NAME;

                if(DATA_SQLITE){
                    $buffer = self::getStatisticSqlite($type, $module, $date, $name, $second);
                }else{
                    $path = '';
                    if ($type != '' && $module != '') {
                        $path = $type . '/' . $module . '/' . str_replace('/', self::NAME_SLASH, $name);
                    }
                    $buffer = self::getStatistic($path, $date, $second);
                }

                break;
            case 'get_log_list':
                $list = [];
                $dir = DATA_LOG_DIR . '/' . $type;
                if (is_dir($dir) && $dh = @opendir($dir)) {
                    while (($file = readdir($dh)) !== false) {
                        if ($file == '.' || $file == '..') continue;
                        $list[] = $file; #is_dir($dir . '/'. $file) &&
                    }
                    closedir($dh);
                }
                /*foreach (glob(DATA_LOG_DIR.'/'.$type.'/*', GLOB_ONLYDIR) as $dir){
                    $list[] = substr(strrchr($dir, '/'), 1);
                }*/
                $buffer = json_encode($list);
                break;
            case 'get_log':
                $start_time = isset($data['start']) ? (int)$data['start'] : 0;
                $end_time = isset($data['end']) ? (int)$data['end'] : 0;
                $code = isset($data['code']) ? $data['code'] : '';
                $msg = isset($data['msg']) ? $data['msg'] : '';
                $offset = isset($data['offset']) ? (int)$data['offset'] : 0;
                $count = isset($data['count']) ? (int)$data['count'] : 10;
                $buffer = self::getLog($type, $module, $name, $start_time, $end_time, $code, $msg, $offset, $count);#
                #$buffer = json_encode($buffer);
                break;
            default :
                $buffer = 'err';
        }
        return $buffer;
    }

    /**
     * 获取层级 type/module/name
     * @param string $path
     * @return array
     */
    protected static function getPath($path)
    {
        $list = [];
        $glob = DATA_STATISTIC_DIR;
        if ($path) { #返回指定模块下的控制
            $glob .= '/' . $path;
            if (strpos($glob,'*')===false && !is_dir($glob)) return $list;
        }else{
            $glob .= '/*';
        }
        #echo $glob,PHP_EOL;
        #var_dump(glob($glob, $flags));
        foreach (glob($glob, GLOB_ONLYDIR) as $k => $filename) { #, $flags
            $name = rtrim($filename, '/');
            $name = substr(strrchr($name, '/'), 1);
            $name = str_replace(self::NAME_SLASH, '/', $name); //把路径符转换回来
            $list[$name] = 0;
        }
        return $list;
    }

    /**
     * @param $sqlite
     * @return Db
     * @throws Exception
     */
    protected static function sqliteDb($sqlite){
        self::$db = new Db([
            'type' => 'pdo',
            'dbms' => 'sqlite',
            'name' => $sqlite,
            'char' => 'utf8',
            'prod'=>true
        ], true);
        return self::$db;
    }

    /**
     * @param $fields
     * @param $group_name
     * @param $order
     * @param int $limit
     * @param $name
     * @param int $start
     * @param int $end
     * @param $ext_case
     * @return string
     */
    protected static function sqliteSql($fields, $group_name, $order, $limit = 100, $name = '', $start = 0, $end = 0, $ext_case='')
    {
        $sql = 'SELECT ' . $fields . ' FROM statistic WHERE 1=1';
        if ($name !== '') {
            if (strpos($name, '*')) {
                $sql .= self::$db->get_real_sql(" AND path LIKE ?", [str_replace('*', '%', $name)]);
            } else {
                $sql .= self::$db->get_real_sql(" AND path=?", [$name]);
            }
        }

        if ($start > 0) {
            $sql .= ' AND time>=' . $start;
        }
        if ($end > 0) {
            $sql .= ' AND time<=' . $end;
        }
        if($ext_case!==''){
            $sql .= ' AND '.$ext_case;
        }
        if($group_name!==''){
            $sql .= ' GROUP BY ' . $group_name;
        }
        if($order!==''){
            $sql .= ' ORDER BY ' . $order;
        }
        if($limit!==0){
            $sql .= ' LIMIT ' . $limit;
        }
        #Log::write($sql, 'sql');
        return $sql;
    }

    /**
     * path失败统计
     * @param $type
     * @param $module
     * @param $date
     * @param string $name
     * @param int $start
     * @param int $end
     * @param int $limit
     * @return array
     * @throws Exception
     */
    protected static function getFailCountSqlite($type, $module, $date, $name='', $start=0, $end=0, $limit=100){
        if($limit>1000) $limit = 1000;

        $list = [];

        $sqliteList = $module === '' ? glob(DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.*.sqlite') : [DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.' . $module . '.sqlite'];
        foreach ($sqliteList as $sqlite) {
            if (!is_file($sqlite)) continue;

            $db = self::sqliteDb($sqlite);

            $sql = self::sqliteSql('path,sum(fail_count) num', 'path', 'num DESC', $limit, $name, $start, $end, 'fail_count>0');

            $res = $db->query($sql);
            while($rs=$db->fetch_array($res)){
                if(isset($list[$rs['path']])){
                    $list[$rs['path']] += $rs['num'];
                }else{
                    $list[$rs['path']] = $rs['num'];
                }
            }
        }

        return $list;
    }

    /**
     * path统计
     * @param $type
     * @param $module
     * @param $date
     * @param string $name
     * @param int $start
     * @param int $end
     * @param int $limit
     * @return array
     * @throws Exception
     */
    protected static function getPathCountSqlite($type, $module, $date, $name='', $start=0, $end=0, $limit=100){
        if($limit>1000) $limit = 1000;

        $list = [];

        $sqliteList = $module === '' ? glob(DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.*.sqlite') : [DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.' . $module . '.sqlite'];
        foreach ($sqliteList as $sqlite) {
            if (!is_file($sqlite)) continue;

            $db = self::sqliteDb($sqlite);

            $sql = self::sqliteSql('path,sum(suc_count+fail_count) num', 'path', 'num DESC', $limit, $name, $start, $end); #, "path<>'".self::ALL_NAME."'"

            $res = $db->query($sql);
            while($rs=$db->fetch_array($res)){
                if(isset($list[$rs['path']])){
                    $list[$rs['path']] += $rs['num'];
                }else{
                    $list[$rs['path']] = $rs['num'];
                }
            }
        }

        return $list;
    }

    /** ip统计
     * @param $type
     * @param $module
     * @param $date
     * @param string $name
     * @param int $start
     * @param int $end
     * @param int $limit
     * @return array
     * @throws Exception
     */
    protected static function getIpCountSqlite($type, $module, $date, $name='', $start=0, $end=0, $limit=100){
        if($limit>1000) $limit = 1000;

        $list = [];

        $sqliteList = $module === '' ? glob(DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.*.sqlite') : [DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.' . $module . '.sqlite'];
        foreach ($sqliteList as $sqlite) {
            if (!is_file($sqlite)) continue;

            $db = self::sqliteDb($sqlite);

            if($name==='') $name = self::ALL_NAME;
            $sql = self::sqliteSql('ip,sum(suc_count+fail_count) num', 'ip', 'num DESC', $limit, $name, $start, $end);

            $res = $db->query($sql);
            while($rs=$db->fetch_array($res)){
                if(isset($list[$rs['ip']])){
                    $list[$rs['ip']] += $rs['num'];
                }else{
                    $list[$rs['ip']] = $rs['num'];
                }
            }
        }

        return $list;
    }

    /**
     * ip统计
     * @param $path
     * @param $date
     * @param int $start
     * @param int $end
     * @param int $limit
     * @return array
     */
    protected static function getIpCountFile($path, $date, $start=0, $end=0, $limit=100)
    {
        if (empty($path) || empty($date)) {
            return [];
        }
        $file = DATA_STATISTIC_DIR . "/{$path}/{$date}";
        if (!is_file($file)) return [];

        // log文件
        $fp = @fopen($file, 'r');
        if (!$fp) {
            return [];
        }

        $data = [];
        while (!feof($fp)) {
            $line = fgets($fp, 4096);
            if ($line) {
                $explode = explode("\t", $line);
                if (count($explode) < 7) {
                    continue;
                }
                list($ip, $time, $suc_count, $suc_cost_time, $fail_count, $fail_cost_time, $code_map) = $explode;

                if($start>0 && $time<$start){
                    continue;
                }
                if($end>0 && $time>$end){
                    continue;
                }

                if (!isset($data[$ip])) {
                    $data[$ip] = 0;
                }
                $data[$ip] += $suc_count + $fail_count;
            }
        }

        fclose($fp);
        rsort($data);

        return array_slice($data, 0, $limit);
    }
    /**
     * 获得统计数据
     * @param $type
     * @param $module
     * @param $date
     * @param string $name
     * @param int $second
     * @param bool $log_ip
     * @return string
     * @throws Exception
     */
    protected static function getStatisticSqlite($type, $module, $date, $name='', $second=300, $log_ip=false)
    {
        $data = [];
        $sqliteList = $module === '' ? glob(DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.*.sqlite') : [DATA_STATISTIC_DIR . '/' . $date . '.' . $type . '.' . $module . '.sqlite'];
        foreach ($sqliteList as $sqlite) {
            if (!is_file($sqlite)) continue;

            $db = self::sqliteDb($sqlite);
            #$db::log_on();
            // 预处理统计数据，每5分钟一行
            // [time=>[ip=>['suc_count'=>xx, 'suc_cost_time'=>xx, 'fail_count'=>xx, 'fail_cost_time'=>xx, 'code_map'=>[code=>count, ..], ..], ..]
            $sql = self::sqliteSql('*', '', '', 0, $name); //'ip,time,slow_times,max_cost_time,min_cost_time,suc_count,suc_cost_time,fail_count,fail_cost_time,code'
            $res = $db->query($sql);
            while ($r = $db->fetch_array($res)) {
                self::formatStatisticData($data, $r, $second, $log_ip);
            } // end while
        }
        // 整理数据
        return self::formatStatisticStr($data);
    }

    /**
     * 获得统计数据
     * @param string $path
     * @param string $date
     * @param int $second
     * @param bool $log_ip 是否记录ip
     * @return string
     */
    protected static function getStatistic($path, $date, $second=300, $log_ip=false)
    {
        if (empty($path) || empty($date)) {
            return '';
        }
        $file = DATA_STATISTIC_DIR . "/{$path}/{$date}";
        if (!is_file($file)) return '';

        // log文件
        $fp = @fopen($file, 'r');
        if (!$fp) {
            return '';
        }

        // 预处理统计数据，每5分钟一行
        // [time=>[ip=>['suc_count'=>xx, 'suc_cost_time'=>xx, 'fail_count'=>xx, 'fail_cost_time'=>xx, 'code_map'=>[code=>count, ..],'slow'=>xx,'max'=>xx,'min'=>xx],..], ..]
        $data = [];
        while (!feof($fp)) {
            $line = fgets($fp, 4096);
            if ($line) {
                $explode = explode("\t", $line);
                if (count($explode) < 10) {
                    continue;
                }
                //list($ip, $time, $suc_count, $suc_cost_time, $fail_count, $fail_cost_time, $code_map, $slow, $max, $min) = $explode;
                $r = [
                    'ip' => $explode[0],
                    'time' => $explode[1],
                    'suc_count' => $explode[2],
                    'suc_cost_time' => $explode[3],
                    'fail_count' => $explode[4],
                    'fail_cost_time' => $explode[5],
                    'code' => $explode[6],
                    'slow_times' => $explode[7],
                    'max_cost_time' => $explode[8],
                    'min_cost_time' => $explode[9]
                ];

                self::formatStatisticData($data, $r, $second, $log_ip);
            } // end if
        } // end while
        fclose($fp);
        // 整理数据
        return self::formatStatisticStr($data);
    }

    /**
     * 处理统计数据
     * @param array $data
     * @param array $item
     * @param int $second
     * @param bool $log_ip
     */
    protected static function formatStatisticData(&$data, $item, $second=300, $log_ip = false)
    {
        $time = ceil($item['time'] / $second) * $second;
        $ip = $log_ip ? $item['ip'] : '127.0.0.1';
        if (!isset($data[$time])) {
            $data[$time] = [];
        }
        if (!isset($data[$time][$ip])) {
            $data[$time][$ip] = array(
                'slow_times' => 0,
                'max_cost_time' => 0,
                'min_cost_time' => 1,
                'suc_count' => 0,
                'suc_cost_time' => 0,
                'fail_count' => 0,
                'fail_cost_time' => 0,
                'code_map' => [],
            );
        }
        $data[$time][$ip]['slow_times'] += $item['slow_times'];
        if ($data[$time][$ip]['max_cost_time'] < $item['max_cost_time']) $data[$time][$ip]['max_cost_time'] = $item['max_cost_time'];
        if ($data[$time][$ip]['min_cost_time'] > $item['min_cost_time']) $data[$time][$ip]['min_cost_time'] = $item['min_cost_time'];

        $data[$time][$ip]['suc_count'] += $item['suc_count'];
        $data[$time][$ip]['suc_cost_time'] += $item['suc_cost_time'];
        $data[$time][$ip]['fail_count'] += $item['fail_count'];
        $data[$time][$ip]['fail_cost_time'] += $item['fail_cost_time'];
        $code_map = json_decode(trim($item['code']), true);
        if ($code_map && is_array($code_map)) {
            foreach ($code_map as $code => $count) {
                if (!isset($data[$time][$ip]['code_map'][$code])) {
                    $data[$time][$ip]['code_map'][$code] = 0;
                }
                $data[$time][$ip]['code_map'][$code] += $count;
            }
        }
    }

    /**
     * 格式输出的统计数据
     * @param array $data
     * @return string
     */
    protected static function formatStatisticStr($data)
    {
        $str = '';
        ksort($data);
        foreach ($data as $time => $items) {
            foreach ($items as $ip => $item) {
                $item['max_cost_time'] = round($item['max_cost_time'], 6);
                $item['min_cost_time'] = round($item['min_cost_time'], 6);
                $item['suc_cost_time'] = round($item['suc_cost_time'], 6);
                $item['fail_cost_time'] = round($item['fail_cost_time'], 6);
                $str .= "$ip\t$time\t{$item['suc_count']}\t{$item['suc_cost_time']}\t{$item['fail_count']}\t{$item['fail_cost_time']}\t" . json_encode($item['code_map']) . "\t{$item['slow_times']}\t{$item['max_cost_time']}\t{$item['min_cost_time']}\n";
            }
        }
        return $str;
    }

    /** 获取指定日志 日志过大时带有条件的时间范围越大http请求容易超时
     * @param $type
     * @param $module
     * @param string $name 查询条件
     * @param int $start_time
     * @param int $end_time
     * @param string $code
     * @param string $msg
     * @param int $offset 偏移
     * @param int $count
     * @return string
     */
    public static function getLog($type, $module, $name='', $start_time = 0, $end_time = 0, $code = '', $msg = '', $offset = 0, $count = 100)
    {
        if($type=='' || $module=='') return '0:0:';//['offset' => 0, 'data' => ''];

        // log文件
        $log_file = DATA_LOG_DIR . '/' . $type .'/' . $module . '/' . ($start_time ? date('Y-m-d', $start_time) : date('Y-m-d'));

        if(!is_file($log_file)) return '0:0:';

        // 读文件
        $fp = fopen($log_file, 'r');
        if (!$fp) {
            return '0:0:';
        }
        //取日志时间匹配正则
        if ($type == 'log') {
            $line = fgets($fp);
            self::$logPattern = $line ? self::getLogPattern($line) : false;
            if (!self::$logPattern) {
                return '0:0:';
            }
        }
        //起始时间为00:00:00点允许误差 *10; 针对23:59日志跨天存储无法查询问题
        if ($start_time > 0 && $start_time <= (mktime(0, 0, 0) + DATA_WRITE_TIME_TICK)) {
            $start_time -= DATA_WRITE_TIME_TICK * 10;
        }

        $isBinarySearch = false;
        $stat = fstat($fp); //获取文件统计信息
        // 如果有时间，则进行二分查找，加速查询
        if ($start_time>0 && $offset == 0 && $stat['size'] > 1024000) { //大于1000K
            $offset = self::binarySearch(0, $stat['size'], $start_time-1, $fp, $type);
            $offset = $offset < 100000 ? 0 : $offset - 100000;
            $isBinarySearch = true;
        }
        // 指定偏移位置
        if ($offset > 0) {
            fseek($fp, (int)$offset - 1);
            $isBinarySearch && fgets($fp); //有二分法偏移的数据可能行不完整 跳过此行
        } else {
            rewind($fp);
        }

        if($type=='log'){ //仅日志 格式:[Y-m-d H:i:s]msg
            $pattern = self::$logPattern[0] == '^' ? '/^(' . substr(self::$logPattern, 1) . ')' : '/(' . self::$logPattern . ')';
            if ($msg) {
                $pattern .= '.*?' . str_replace('/', '\/', preg_quote($msg));
            }
            $pattern .= '/';
        }else{
            // 默认日志的正则表达式
            $pattern = "/^([\d: \-]+)\.\d+\t\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\t";
            if ($name!=='') {
                $pattern .= str_replace(['\*','/'],['.*?','\/'],preg_quote($name)) . "\t";
            } else {
                $pattern .= ".*\t";
            }
            if ($code !== '') {
                $pattern .= 'code:'.preg_quote($code)."\t";
            } else {
                $pattern .= "code:\-?\d+\t"; //可能负数
            }
            if ($msg) {
                $pattern .= 'msg:'.preg_quote($msg);
            }
            $pattern .= '/';
        }

        // 查找符合条件的数据
        $now_count = 0;
        $log_buffer = '';

        while (!feof($fp)) {
            $line = fgets($fp);
            // 收集符合条件的log
            if (preg_match($pattern, $line, $match)) {
                // 判断时间是否符合要求
                $time = strtotime($match[1]);
                if ($start_time>0 && $time < $start_time) {
                    continue;
                }
                if ($end_time>0 && $time > $end_time) {
                    break;
                }

                $log_buffer .= $line;

                if (++$now_count >= $count) {
                    break;
                }
            }
        }
        // 记录偏移位置
        $offset = ftell($fp);
        fclose($fp);
        return $offset.':'.$stat['size'].':'.$log_buffer;
    }

    /**
     * 日志二分查找法
     * @param int $start_point
     * @param int $end_point
     * @param int $start_time
     * @param resource $fp
     * @param string $type
     * @return int
     */
    protected static function binarySearch($start_point, $end_point, $start_time, $fp, $type='')
    {
        if ($end_point - $start_point < 65535) {
            return $start_point;
        }

        // 计算中点
        $mid_point = (int)(($end_point + $start_point) / 2);

        // 定位文件指针在中点
        fseek($fp, $mid_point - 1);

        // 读第一行
        $line = fgets($fp);
        if (feof($fp) || false === $line) {
            return $start_point;
        }

        // 第一行可能数据不全，再读一行
        $line = fgets($fp);
        if (feof($fp) || false === $line || trim($line) == '') {
            return $start_point;
        }

        // 判断是否越界
        $current_point = ftell($fp);
        if ($current_point >= $end_point) {
            return $start_point;
        }
        // 获得时间
        if ($type == 'log') {
            $pattern = '/' . self::$logPattern . '/'; //取日期时间 Y-m-d H:i:s
            if (preg_match($pattern, $line, $match)) {
                $tmp_time = strtotime($match[0]);
            } else {
                return $start_point;
            }
        } else {
            $tmp = explode("\t", $line);
            $tmp_time = strtotime($tmp[0]);
        }

        // 判断时间，返回指针位置
        if ($tmp_time > $start_time) {
            return self::binarySearch($start_point, $current_point, $start_time, $fp, $type);
        } elseif ($tmp_time < $start_time) {
            return self::binarySearch($current_point, $end_point, $start_time, $fp, $type);
        } else {
            return $current_point;
        }
    }
}