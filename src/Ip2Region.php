<?php
/**
 * ip2region IP搜索程序客户端类
 *
 * @Author  chenxin<chenxin619315@gmail.com>
 * @Date  2015-10-29
 * @Editor  LuoYan<51085726@qq.com>
 * @Date  2019-10-1
 */

declare (strict_types=1);

namespace LuoYan\IpCity;

use think\exception\HttpException;
use think\facade\Cache;

class Ip2Region
{
    /**
     * 数据库文件处理操作者
     */
    private $dbFileHandler = null;

    /**
     * 原始数据库文件
     */
    private $dbFile;

    /**
     * 头部块信息
     */
    private $HeaderSip = null;
    private $HeaderPtr = null;
    private $headerLen = 0;

    /**
     * 超级块索引信息
     */
    private $firstIndexPtr = 0;
    private $lastIndexPtr = 0;
    private $totalBlocks = 0;

    /**
     * 仅用于内存模式
     * 原始数据库二进制字符串
     */
    private $dbBinStr = null;

    /**
     * 构造方法
     *
     * @param string $ip2regionFile 数据库文件
     */
    public function __construct(string $ip2regionFile = __DIR__ . '/Ip2Region.db')
    {
        $this->dbFile = $ip2regionFile;
    }

    /**
     * 从字节缓冲区读取long
     *
     * @param $b
     * @param $offset
     * @return int|string
     */
    private static function getLong($b, $offset)
    {
        $val = (
            (ord($b[$offset++])) |
            (ord($b[$offset++]) << 8) |
            (ord($b[$offset++]) << 16) |
            (ord($b[$offset]) << 24)
        );

        // 如果在32位操作系统上，则将有符号int转换为无符号int
        if ($val < 0 && PHP_INT_SIZE == 4) {
            $val = sprintf("%u", $val);
        }

        return $val;
    }

    /**
     * 内存搜索算法，会比基于硬盘的搜索快很多
     * 注意：在将其置于公共调用之前调用一次可以使其线程安全
     *
     * @param string $ip 需要查询额IP
     * @return array|false 成功返回数据，失败返回false
     */
    public function memorySearch(string $ip)
    {
        $cache = Cache::get('Ip2Region');
        if ($cache) {
            $this->dbBinStr = $cache['dbBinStr'];
            $this->firstIndexPtr = $cache['firstIndexPtr'];
            $this->lastIndexPtr = $cache['lastIndexPtr'];
            $this->totalBlocks = $cache['totalBlocks'];
        } else {
            // 第一次检查并加载二进制字符串
            if ($this->dbBinStr == null) {
                $this->dbBinStr = file_get_contents($this->dbFile);
                if ($this->dbBinStr == false) {
                    throw new HttpException(500, '无法打开IP2Region数据库文件：' . $this->dbFile);
                }

                $this->firstIndexPtr = self::getLong($this->dbBinStr, 0);
                $this->lastIndexPtr = self::getLong($this->dbBinStr, 4);
                $this->totalBlocks = ($this->lastIndexPtr - $this->firstIndexPtr) / 12 + 1;

                $Ip2Region = [
                    'dbBinStr' => $this->dbBinStr,
                    'firstIndexPtr' => $this->firstIndexPtr,
                    'lastIndexPtr' => $this->lastIndexPtr,
                    'totalBlocks' => $this->totalBlocks,
                ];
                Cache::set('Ip2Region', $Ip2Region, 86400);
            }
        }

        if (is_string($ip)) $ip = self::safeIp2long($ip);

        // 定义数据的二进制搜索
        $l = 0;
        $h = $this->totalBlocks;
        $dataPtr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = $this->firstIndexPtr + $m * 12;
            $sip = self::getLong($this->dbBinStr, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($this->dbBinStr, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($this->dbBinStr, $p + 8);
                    break;
                }
            }
        }

        // 不匹配就停止
        if ($dataPtr == 0) return false;

        // 获取数据
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);

        return array(
            'city_id' => self::getLong($this->dbBinStr, $dataPtr),
            'region' => substr($this->dbBinStr, $dataPtr + 4, $dataLen - 4)
        );
    }

    /**
     * 安全的IP处理方法
     *
     * @param string $ip
     * @return int|string
     */
    public static function safeIp2long(string $ip)
    {
        $ip = ip2long($ip);

        // 如果在32位操作系统上，则将有符号int转换为无符号int
        if ($ip < 0 && PHP_INT_SIZE == 4) {
            $ip = sprintf("%u", $ip);
        }

        return $ip;
    }

    /**
     * 用b-tree搜索算法获取与指定ip相关联的数据块
     * 注意：非线程安全
     *
     * @param $ip
     * @return  array|bool 成功返回数组，失败返回false
     */
    public function btreeSearch(string $ip)
    {
        if (is_string($ip)) $ip = self::safeIp2long($ip);

        // 检查并加载头部
        if ($this->HeaderSip == null) {
            // 检查并打开原始数据库文件
            if ($this->dbFileHandler == null) {
                $this->dbFileHandler = fopen($this->dbFile, 'r');
                if ($this->dbFileHandler == false) {
                    throw new HttpException(500, '无法打开IP2Region数据库文件：' . $this->dbFile);
                }
            }

            fseek($this->dbFileHandler, 8);
            $buffer = fread($this->dbFileHandler, 8192);

            // 填充头部
            $idx = 0;
            $this->HeaderSip = array();
            $this->HeaderPtr = array();
            for ($i = 0; $i < 8192; $i += 8) {
                $startIp = self::getLong($buffer, $i);
                $dataPtr = self::getLong($buffer, $i + 4);
                if ($dataPtr == 0) break;

                $this->HeaderSip[] = $startIp;
                $this->HeaderPtr[] = $dataPtr;
                $idx++;
            }

            $this->headerLen = $idx;
        }

        // 1. 用二进制搜索定义索引块
        $l = 0;
        $h = $this->headerLen;
        $s_ptr = 0;
        $e_ptr = 0;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);

            // 完美匹配，返回即可
            if ($ip == $this->HeaderSip[$m]) {
                if ($m > 0) {
                    $s_ptr = $this->HeaderPtr[$m - 1];
                    $e_ptr = $this->HeaderPtr[$m];
                } else {
                    $s_ptr = $this->HeaderPtr[$m];
                    $e_ptr = $this->HeaderPtr[$m + 1];
                }

                break;
            }

            // 小于中间值
            if ($ip < $this->HeaderSip[$m]) {
                if ($m == 0) {
                    $s_ptr = $this->HeaderPtr[$m];
                    $e_ptr = $this->HeaderPtr[$m + 1];
                    break;
                } else if ($ip > $this->HeaderSip[$m - 1]) {
                    $s_ptr = $this->HeaderPtr[$m - 1];
                    $e_ptr = $this->HeaderPtr[$m];
                    break;
                }
                $h = $m - 1;
            } else {
                if ($m == $this->headerLen - 1) {
                    $s_ptr = $this->HeaderPtr[$m - 1];
                    $e_ptr = $this->HeaderPtr[$m];
                    break;
                } else if ($ip <= $this->HeaderSip[$m + 1]) {
                    $s_ptr = $this->HeaderPtr[$m];
                    $e_ptr = $this->HeaderPtr[$m + 1];
                    break;
                }
                $l = $m + 1;
            }
        }

        // 什么都不匹配就停下来
        if ($s_ptr == 0) return false;

        // 2. 搜索索引块以定义数据
        $blockLen = $e_ptr - $s_ptr;
        fseek($this->dbFileHandler, $s_ptr);
        $index = fread($this->dbFileHandler, $blockLen + 12);

        $dataPtr = 0;
        $l = 0;
        $h = $blockLen / 12;
        while ($l <= $h) {
            $m = (($l + $h) >> 1);
            $p = (int)($m * 12);
            $sip = self::getLong($index, $p);
            if ($ip < $sip) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($index, $p + 4);
                if ($ip > $eip) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($index, $p + 8);
                    break;
                }
            }
        }

        // 没有匹配
        if ($dataPtr == 0) return false;

        // 3. 获取数据
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);

        fseek($this->dbFileHandler, $dataPtr);
        $data = fread($this->dbFileHandler, $dataLen);

        return [
            'city_id' => self::getLong($data, 0),
            'region' => substr($data, 4)
        ];
    }

    /**
     * 析构方法，资源销毁
     */
    public function __destruct()
    {
        if ($this->dbFileHandler != null) {
            fclose($this->dbFileHandler);
        }

        $this->dbBinStr = null;
        $this->HeaderSip = null;
        $this->HeaderPtr = null;
    }
}
