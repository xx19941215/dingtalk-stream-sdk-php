<?php
declare(strict_types=1);

use OpenDingTalk\Stream\DingTalkStreamClient;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\NullLogger;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "请先在项目根目录执行: composer install\n");
    exit(1);
}
require $autoload;

$hasMbstring = function_exists('mb_strtolower') || extension_loaded('mbstring');
if (!$hasMbstring) {
    fwrite(STDERR, "缺少 mbstring 扩展：请在 Windows 的 php.ini 启用 extension=mbstring，或在 Linux 安装 php-mbstring 后重试。\n");
    exit(1);
}

$config = require __DIR__ . '/config.php';
$http = new HttpClient(['timeout' => 10]);
$logger = new NullLogger();
$client = new Xx19941215\DingTalkStream\DingTalkStreamClient($config, $http, $logger);

/**
 * 处理 EVENT 事件
 *
 * @param array $message
 * @return array
 */
function handleEvent(array $message): array
{
    return ['status' => 'handled', 'type' => 'EVENT'];
}

/**
 * 处理 CALLBACK 回调
 *
 * @param array $message
 * @return array
 */
function handleCallback(array $message): array
{
    return ['ack' => true, 'type' => 'CALLBACK'];
}

$client->registerHandler('EVENT', 'handleEvent');
$client->registerHandler('CALLBACK', 'handleCallback');
$client->connect();
