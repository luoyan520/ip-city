<?php
/**
 * IpLocation 通过IP获取客户端所在城市类
 *
 * @Author  LuoYan<51085726@qq.com>
 * @Date  2020-04-29
 */

declare (strict_types=1);

namespace LuoYan\IpLocation;

use think\facade\Cache;
use think\facade\Config;

class IpLocation
{
    /**
     * 透过CDN获取客户端真实IP
     * @param $request
     * @return string $ip
     */
    public static function getIp($request)
    {
        $ip = $request->ip();
        if ($ip == '::1') $ip = '127.0.0.1';
        return $ip;
    }

    /**
     * 获取IP对应地理位置并缓存（本地数据库版）
     * @param string $ip
     * @return array
     */
    public static function getipLocation(string $ip): array
    {
        if ($ip == '::1') return array('nation' => '', 'province' => '', 'city' => '保留地址');
        if ($ip == '127.0.0.1') return array('nation' => '', 'province' => '', 'city' => '本机地址');

        $cache = Cache::get('ipLocation_' . $ip);
        if ($cache) return $cache;

        $ip2region = new Ip2Region();
        $data = $ip2region->memorySearch($ip);
        unset($ip2region);

        if (!$data) return [];

        $data = explode('|', $data['region']);
        $location = array();
        $location['nation'] = $data[1] ? $data[1] : '';
        $location['province'] = $data[2] ? $data[2] : '';
        $location['city'] = $data[3] ? $data[3] : '';
        Cache::set('ipLocation_' . $ip, $location, 3600);

        return $location;
    }

    /**
     * 根据IP查询所在城市(高德数据库版,每日限1万次)
     * @param string $ip
     * @return array 省份和城市信息
     */
    public static function getipLocationByAMap(string $ip): array
    {
        $api_key = Config::get('ip_location.amap_api_key');

        $url = 'https://restapi.amap.com/v3/ip';
        $para = '?key=' . $api_key . '&ip=' . $ip;
        $receive = self::curl($url . $para);
        $data = json_decode($receive, true);

        if ($data['status'] !== 1) return [];
        if (!isset($data['province'])) return [];
        if (!$data['province']) return [];

        return [
            'nation' => '中国',
            'province' => $data['province'],
            'city' => $data['city']
        ];
    }

    /**
     * 简易Curl方法
     * @param string $url 访问地址
     * @param string $post post参数
     * @param string $cookie 要发送的cookie
     * @return string|bool 返回信息
     */
    public static function curl(string $url, $post = '', $cookie = ''): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $headInit[] = "Accept:*/*";
        $headInit[] = "Accept-Encoding:gzip,deflate,sdch";
        $headInit[] = "Accept-Language:zh-CN,zh;q=0.8";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headInit);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        if ($cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }

    /**
     * 根据IP查询所在城市(IPIP数据库版,每秒限5次)
     * @param string $ip
     * @return array
     */
    public static function getipLocationByIpip(string $ip): array
    {
        $url = 'http://freeapi.ipip.net/' . $ip;

        $receive = self::curl($url);
        $data = json_decode($receive, true);

        return [
            'nation' => $data[0],
            'province' => $data[1],
            'city' => $data[2]
        ];
    }

    /**
     * 根据IP查询所在城市(腾讯数据库版,每日限1万次)
     * @param string $ip
     * @return array 省份和城市信息
     */
    public static function getipLocationByTencent(string $ip): array
    {
        $api_key = Config::get('ip_location.tencent_api_key');// 腾讯IP接口秘钥
        $sign_str = Config::get('ip_location.tencent_sign_str');// 腾讯IP接口签名字符串

        $url = 'https://apis.map.qq.com';
        $para = '/ws/location/v1/ip?ip=' . $ip . '&key=' . $api_key;
        $sig = '&sig=' . md5($para . $sign_str);// 计算签名
        $receive = self::curl($url . $para . $sig);
        $data = json_decode($receive, true);
        if ($data['status'] !== 0) return [];

        return [
            'nation' => $data['result']['ad_info']['nation'],
            'province' => $data['result']['ad_info']['province'],
            'city' => $data['result']['ad_info']['city']
        ];
    }
}
