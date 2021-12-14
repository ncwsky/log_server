<?php

class GetOpt
{
    private static $options = [];
    /**
     * 解析命令
     * @param $short
     * @param array $long
     * @return array
     */
    public static function parse($short, $long = [])
    {
        self::$options = getopt($short, $long);
        return self::$options;
    }

    /**
     * 获取命令参数值
     * @param $name
     * @param string $longName
     * @param mixed $def
     * @return mixed|string
     */
    public static function val($name, $longName = '', $def = '')
    {
        $val = $def;
        $val = isset(self::$options[$name]) ? self::$options[$name] : $val;
        if ($longName !== '') {
            $val = isset(self::$options[$longName]) ? self::$options[$longName] : $val;
        }
        return $val;
    }

    /**
     * 是否存在命令参数
     * @param $name
     * @param string $longName
     * @return bool
     */
    public static function has($name, $longName = '')
    {
        if (isset(self::$options[$name])) return true;
        if ($longName !== '' && isset(self::$options[$longName])) {
            return true;
        }
        return false;
    }
}
