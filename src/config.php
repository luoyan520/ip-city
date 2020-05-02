<?php
// +----------------------------------------------------------------------
// | IpCity扩展设置
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // 高德地图API秘钥
    'amap_api_key'       =>    Env::get('IpCity.AmapApiKey', ''),

    // 腾讯地图接口
    'tencent_api_key'    =>    Env::get('IpCity.TencentApiKey', ''),
    'tencent_sign_str'   =>    Env::get('IpCity.TencentSignStr', ''),
];