<?php
/**
 *
 * curl方法
 *
 * @param string $url 要访问的url
 * @param string|int $post 要post的数据
 * @param string|int $cookie 要模拟的cookies
 * @return bool|string 返回结果
 */
if (!function_exists('curl')) {
    function curl(string $url, $post = 0, $cookie = 0): string
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
}