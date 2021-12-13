<?php

/**
 * 日志统计客户端
 */
class LogClient
{
    /**
     * udp 包最大长度
     * @var integer
     */
    const MAX_PACKAGE_BODY_SIZE  = 65507; #65535-20Ip包头-8UDP包头 udp某些情况最大只有MTU-1500(1472)、8192(8164)

    /**
     * char类型能保存的最大数值
     * @var integer
     */
    const MAX_CHAR_VALUE = 255;

    /**
     * 统计类型
     */
    const TYPE_INTERFACE_FAIL = 0; #失败[接口]
    const TYPE_INTERFACE_OK   = 1; #成功[接口]
    const TYPE_METRIC         = 2; #指标
    const TYPE_LOG            = 4; #仅日志

    /**
     * [timeKey=>time_start, ... ]
     * @var array
     */
    protected static $timeMap = [];

    public static $address = '127.0.0.1:11024';
    public static $module = 'default';
    public static $referer_ip = ''; // 来源ip 未指定默认是读取当前服务的ip

    protected static function _module(&$module, &$module_len){
        $module_len = strlen($module);

        // 防止模块名过长
        if(self::$referer_ip!==''){
            $ip_len = strlen(self::$referer_ip)+1; // 分隔符@加1
            $max_module_len = self::MAX_CHAR_VALUE - $ip_len;

            if($module_len > $max_module_len)
            {
                $module = substr($module, 0, $max_module_len);
                $module_len = $max_module_len;
            }
            $module .= '@'.self::$referer_ip;
            $module_len += $ip_len;
        }else{
            if($module_len > self::MAX_CHAR_VALUE)
            {
                $module = substr($module, 0, self::MAX_CHAR_VALUE);
                $module_len = self::MAX_CHAR_VALUE;
            }
        }
    }
    protected static function _name(&$name, &$name_len){
        $name_len = strlen($name);
        // 防止名过长
        if($name_len > self::MAX_CHAR_VALUE)
        {
            $name = substr($name, 0, self::MAX_CHAR_VALUE);
            $name_len = self::MAX_CHAR_VALUE;
        }
    }
    protected static function _msg(&$msg, &$size_len, $package_head_len, $module_len=0, $name_len=0){
        // 防止msg过长
        $allow_size = self::MAX_PACKAGE_BODY_SIZE - $package_head_len - $module_len - $name_len;
        $size_len = strlen($msg);
        if(strlen($msg) > $allow_size)
        {
            $msg = substr($msg, 0, $allow_size);
            $size_len = $allow_size;
        }
    }

    /**
     * 记录时间
     * @param $module
     * @param $name
     * @return float|string
     */
    protected static function _time_start($module, $name){
        $timeKey = $module . '/' . $name;
        if (isset(self::$timeMap[$timeKey]) && self::$timeMap[$timeKey] > 0) {
            $time_start = self::$timeMap[$timeKey];
            unset(self::$timeMap[$timeKey]);
        } else {
            $time_start = microtime(true);
        }
        return $time_start;
    }

    /**
     * 模块接口上报消耗时间记时
     * @param string $name
     * @param null $module
     * @return void
     */
    public static function tick($name = '', $module=null)
    {
        if($module===null) $module = self::$module;
        self::$timeMap[$module . '/' . $name] = microtime(true);
    }
    /**
     * 模块接口上报消耗时间擦写
     * @param string $old_name
     * @param string $new_name
     * @param null $module
     * @return void
     */
    public static function erase($old_name, $new_name, $module=null)
    {
        if($module===null) $module = self::$module;
        if(isset(self::$timeMap[$module . '/' . $old_name])){
            self::$timeMap[$module . '/' . $new_name] = self::$timeMap[$module . '/' . $old_name];
            unset(self::$timeMap[$module . '/' . $old_name]);
        }
    }

    /**
     * 上报统计数据
     * @param string $name
     * @param int $code
     * @param string $msg
     * @param int $type
     * @param null $module
     * @return boolean
     */
    public static function report($name, $code = 0, $msg = '', $type = self::TYPE_INTERFACE_OK, $module = null)
    {
        if ($module === null) $module = self::$module;
        if ($type != self::TYPE_INTERFACE_OK && $type != self::TYPE_INTERFACE_FAIL) return false;
        $time_start = self::_time_start($module, $name);

        $cost_time = microtime(true) - $time_start; #消耗时间

        self::_module($module, $module_len);
        self::_name($name, $name_len);
        self::_msg($msg, $size_len, 21, $module_len, $name_len);

        // 打包  21:1+1+1+8+4+4+2
        $bin_data = pack('CCCdfln', $type, $module_len, $name_len, $time_start, $cost_time, $code, $size_len).$module.$name.$msg;

        return self::sendData($bin_data);
    }

    public static function fail($name, $code = 0, $msg = '', $module = null)
    {
        return self::report($name, $code, $msg, self::TYPE_INTERFACE_FAIL, $module);
    }

    public static function ok($name, $code = 0, $msg = '', $module = null)
    {
        return self::report($name, $code, $msg, self::TYPE_INTERFACE_OK, $module);
    }

    public static function metric($name, $msg, $module = null)
    {
        if ($module === null) $module = self::$module;
        $time_start = self::_time_start($module, $name);

        self::_module($module, $module_len);
        self::_name($name, $name_len);
        self::_msg($msg, $size_len, 13, $module_len, $name_len);

        // 打包  13:1+1+1+8+2
        $bin_data = pack('CCCdn', self::TYPE_METRIC, $module_len, $name_len, $time_start, $size_len).$module.$name.$msg;

        return self::sendData($bin_data);
    }

    public static function log($msg, $module = null)
    {
        if ($module === null) $module = self::$module;

        self::_module($module, $module_len);
        self::_msg($msg, $size_len, 4, $module_len);

        // 打包  4:1+1+2
        $bin_data = pack('CCn', self::TYPE_LOG, $module_len, $size_len).$module.$msg;

        return self::sendData($bin_data);
    }

    /**
     * 发送数据给统计系统
     * @param string $buffer
     * @return boolean
     */
    protected static function sendData($buffer)
    {
        $socket = stream_socket_client('udp://' . self::$address);
        if (!$socket) {
            return false;
        }
        stream_socket_sendto($socket, $buffer);
        fclose($socket);
        return true;#stream_socket_sendto($socket, $buffer) == strlen($buffer);
    }
}