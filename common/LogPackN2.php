<?php

use Workerman\Connection\TcpConnection;

/**
 * LogPackN2 Protocol.
 */
class LogPackN2
{
    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @return int
     */
    public static function input($buffer, TcpConnection $connection)
    {
        if (\strlen($buffer) < 6) {
            return 0;
        }
        $unpack_data = \unpack('Cnull/Ntotal_length/Cstart', $buffer);
        if ($unpack_data['null'] !== 0x00 || $unpack_data['start'] !== 0x02) {
            $connection->destroy(); //数据错误，关闭连接
            return 0;
        }
        return $unpack_data['total_length'];
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return \substr($buffer, 6);
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        $total_length = 6 + \strlen($buffer);
        return \pack('CNC', 0x00, $total_length, 0x02) . $buffer;
    }
}