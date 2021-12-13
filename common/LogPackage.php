<?php
/**
 * 日志统计包-打包解包
 * struct LogPackage
 * {
 *     unsigned char type;
 *     unsigned char module_len;
 *     unsigned char name_len;
 *     double time_start;
 *     float cost_time;
 *     int code;
 *     unsigned short msg_len;
 *
 *     char[module_len] module;
 *     char[name_len] name;
 *     char[msg_len] msg;
 * }
 */
class LogPackage
{
    const TYPE_METRIC = 2; #指标
    const TYPE_LOG = 4; #仅日志

    /**
     * 包头长度
     * @var integer
     */
    const PACKAGE_HEAD_LEN = 21;
    const LOG_HEAD_LEN = 4;
    const METRIC_HEAD_LEN = 13;

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
     * @param $recv_buffer
     * @return int
     */
    public static function input($recv_buffer)
    {
        $str_len = strlen($recv_buffer);
        if($str_len===0) return 0;

        $type = unpack('C', $recv_buffer)[1];
        if ($type === self::TYPE_LOG) {
            if ($str_len < self::LOG_HEAD_LEN) {
                return 0;
            }
            $data = unpack('Ctype/Cmodule_len/nmsg_len', $recv_buffer);
            return $data['module_len'] + $data['msg_len'] + self::LOG_HEAD_LEN;
        } elseif ($type === self::TYPE_METRIC) {
            if ($str_len < self::METRIC_HEAD_LEN) {
                return 0;
            }
            $data = unpack('Ctype/Cmodule_len/Cname_len/dtime_start/nmsg_len', $recv_buffer);
            return $data['module_len'] + $data['name_len'] + $data['msg_len'] + self::METRIC_HEAD_LEN;
        }

        if ($str_len < self::PACKAGE_HEAD_LEN) {
            return 0;
        }

        //临时兼容旧的
        $fmt = 'Cmodule_len/Cname_len/dtime_start/fcost_time/Ctype/lcode/nmsg_len';
        if ($type < self::TYPE_METRIC) {
            $fmt = 'Ctype/Cmodule_len/Cname_len/dtime_start/fcost_time/lcode/nmsg_len';
        }
        $data = unpack($fmt, $recv_buffer);
        return $data['module_len'] + $data['name_len'] + $data['msg_len'] + self::PACKAGE_HEAD_LEN;
    }
    public static function encode($buffer){
        return self::toEncode('', '', 0, 0, 0, 0, $buffer);
    }
    /**
     * 编码
     * @param string $module
     * @param string $name
     * @param float $time_start
     * @param float $cost_time
     * @param int $type
     * @param int $code
     * @param string $msg
     * @return string
     */
    public static function toEncode($module, $name, $time_start, $cost_time, $type, $code = 0, $msg = '')
    {
        $module_len = strlen($module);
        $name_len = strlen($name);
        // 防止模块名过长
        if($module_len > self::MAX_CHAR_VALUE)
        {
            $module = substr($module, 0, self::MAX_CHAR_VALUE);
            $module_len = self::MAX_CHAR_VALUE;
        }
        // 防止控制名过长
        if($name_len > self::MAX_CHAR_VALUE)
        {
            $name = substr($name, 0, self::MAX_CHAR_VALUE);
            $name_len = self::MAX_CHAR_VALUE;
        }

        // 防止msg过长
        $allow_size = self::MAX_PACKAGE_BODY_SIZE - self::PACKAGE_HEAD_LEN - $module_len - $name_len;# - $time_len;
        $size_len = strlen($msg);
        if(strlen($msg) > $allow_size)
        {
            $msg = substr($msg, 0, $allow_size);
            $size_len = $allow_size;
        }

        // 打包  21:1+1+1+8+4+4+2
        return pack('CCCdfln', $type, $module_len, $name_len, $time_start, $cost_time, $code, $size_len) . $module . $name . $msg;
    }

    /**
     * 解包
     * @param string $recv_buffer
     * @return array
     */
    public static function decode($recv_buffer)
    {
        // 解包
        $type = unpack('C', $recv_buffer)[1];
        $stepStartLen = self::PACKAGE_HEAD_LEN;
        if ($type === self::TYPE_LOG) {
            $stepStartLen = self::LOG_HEAD_LEN;
            $data = unpack('Ctype/Cmodule_len/nmsg_len', $recv_buffer);
        } elseif ($type === self::TYPE_METRIC) {
            $stepStartLen = self::METRIC_HEAD_LEN;
            $data = unpack('Ctype/Cmodule_len/Cname_len/dtime_start/nmsg_len', $recv_buffer);
        } elseif ($type < self::TYPE_METRIC) {
            $data = unpack('Ctype/Cmodule_len/Cname_len/dtime_start/fcost_time/lcode/nmsg_len', $recv_buffer);
        } else { //临时兼容下旧的
            $data = unpack('Cmodule_len/Cname_len/dtime_start/fcost_time/Ctype/lcode/nmsg_len', $recv_buffer);
            $type = $data['type'];
        }

        $module = substr($recv_buffer, $stepStartLen, $data['module_len']);
        $stepStartLen += $data['module_len'];

        $name = '';
        $time_start = 0;
        $cost_time = 0;
        $code = 0;
        if ($type !== self::TYPE_LOG) {
            $name = substr($recv_buffer, $stepStartLen, $data['name_len']);
            $stepStartLen += $data['name_len'];

            $time_start = $data['time_start'];
            if ($type < self::TYPE_METRIC) { //接口
                $cost_time = $data['cost_time'];
                $code = $data['code'];
            }
        }
        $msg = substr($recv_buffer, $stepStartLen);

        return [
            'module'    => $module,
            'name'      => $name,
            'time_start'=> $time_start,
            'cost_time' => $cost_time,
            'type'      => $type,
            'code'      => $code,
            'msg'       => $msg,
            'msg_len'   => $data['msg_len']
        ];
    }
}