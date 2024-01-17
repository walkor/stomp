<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Stomp\Protocols;

/**
 * Stomp Protocol.
 * 
 * @author    walkor<walkor@workerman.net>
 */
#[\AllowDynamicProperties]
class Stomp
{
    /**
     * Check the integrity of the package.
     *
     * @param string        $buffer
     * @return int
     */
    public static function input($buffer)
    {
        if ($buffer[0] == "\n" || $buffer[0] == "\x00") {
            return 1;
        }
        $pos = strpos($buffer, "\n\n");
        if (false === $pos) {
            return 0;
        }
        $head = substr($buffer, 0, $pos);
        $header_data = explode("\n", trim($head, "\n"));
        $command = $header_data[0];
        if (!in_array($command, ['SEND', 'MESSAGE', 'ERROR'])) {
            return $pos + 3;
        }
        unset($header_data[0]);
        foreach ($header_data as $line) {
            list($key, $value) = explode(':', $line, 2);
            if (strtolower($key) === 'content-length') {
                return $value + $pos + 3;
            }
        }
        $end_pos = strpos($buffer, "\x00");
        if (!$end_pos) {
            return 0;
        }
        return $end_pos + 1;
    }

    /**
     * Encode.
     *
     * @param array $data
     * @return string
     */
    public static function encode(array $data)
    {
        if ($data['cmd'] === 'HEARTBEAT') return "\n";
        $headers = '';
        if (isset($data['headers'])) {
            foreach ($data['headers'] as $key => $value) {
                $headers .= "$key:$value\n";
            }
        }
        $body = isset($data['body']) ? $data['body'] : '';
        return $data['cmd']."\n$headers\n".$body."\x00";
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return array
     */
    public static function decode($buffer)
    {
        if ($buffer[0] == "\n" || $buffer[0] == "\x00") {
            return ['cmd' => 'HEARTBEAT', 'headers' => [], 'body' => ''];
        }
        list($head, $body) = explode("\n\n", $buffer, 2);
        $header_data = explode("\n", trim($head, "\n"));
        $command = $header_data[0];
        unset($header_data[0]);
        $headers = [];
        foreach ($header_data as $line) {
            list($key, $value) = explode(':', $line, 2);
            if (!isset($headers[$key])) {
                $headers[$key] = $value;
            }
        }
        return ['cmd' => $command, 'headers' => $headers, 'body' => substr($body, 0, -1)];
    }

}
