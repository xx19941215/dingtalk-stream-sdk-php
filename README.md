# DingTalk Stream SDK for PHP

支持钉钉 Stream 模式的事件推送、机器人收消息以及卡片回调。相比 Webhook 模式，Stream 模式可更简单地接入各类事件与回调。

## 特性
- 无框架依赖，开箱即用，遵循 PSR-4 与 PSR-3
- 统一处理 SYSTEM/EVENT/CALLBACK 三类消息，自动 ping/pong 与断线重连
- 可注入自定义 Guzzle HTTP 客户端与日志记录器
- 完整函数级注释，便于二次开发

## 环境要求
- PHP >= 7.4
- 依赖：`textalk/websocket`、`guzzlehttp/guzzle`、`psr/log`
- 扩展：`mbstring`（用于 URI 解析等函数，如 `mb_strtolower`）
- 需要在钉钉开发者后台创建企业内部应用并开通机器人 Stream 模式，获取 `AppKey` 与 `AppSecret`

## 启用 mbstring 扩展
- Windows：打开 `php.ini`，确保存在并取消注释 `extension=mbstring`，然后重启 PHP 环境（如重启 Apache/Nginx 或命令行窗口）。
- Linux（示例，按你的发行版调整）：
  - Debian/Ubuntu：`sudo apt-get install php-mbstring && sudo service php7.4-fpm restart`
  - CentOS/RHEL：`sudo yum install php-mbstring && sudo systemctl restart php-fpm`

## 安装
### 方式一：作为本地路径包使用
在你的主项目 `composer.json` 增加：
```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../dingtalk-stream-sdk-php",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "xx19941215/dingtalk-stream-sdk-php": "*"
  }
}
```
然后在主项目根目录执行：
```bash
composer update xx19941215/dingtalk-stream-sdk-php
```

### 方式二：作为 VCS 仓库使用
将本仓库推送到你的 Git 服务后，在主项目 `composer.json` 增加：
```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://your.git.server/xx19941215/dingtalk-stream-sdk-php.git"
    }
  ],
  "require": {
    "xx19941215/dingtalk-stream-sdk-php": "^1.0"
  }
}
```
然后执行：
```bash
composer require xx19941215/dingtalk-stream-sdk-php
```

## 快速开始
```php
use OpenDingTalk\Stream\DingTalkStreamClient;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\NullLogger;

$config = [
    'clientId' => '你的AppKey',
    'clientSecret' => '你的AppSecret',
    'subscriptions' => [
        ['type' => 'EVENT', 'topic' => '*'],
        ['type' => 'CALLBACK', 'topic' => '*'],
    ],
    'ua' => 'your-app/1.0.0',
    'localIp' => '10.0.0.1',
    'debug' => true,
];

$http = new HttpClient(['timeout' => 10]);
$logger = new NullLogger();

$client = new DingTalkStreamClient($config, $http, $logger);

// 注册事件处理器（EVENT）
$client->registerHandler('EVENT', function(array $message) {
    // 解析 $message['data']，执行业务逻辑
    return ['status' => 'handled'];
});

// 注册卡片/回调处理器（CALLBACK）
$client->registerHandler('CALLBACK', function(array $message) {
    // 处理卡片交互
    return ['ack' => true];
});

// 建立连接并开始监听
$client->connect();
```

## 消息类型
- SYSTEM：内部系统消息
  - `ping`：SDK 自动响应 `pong`
  - `disconnect`：断开请求，SDK 会尝试重连
- EVENT：事件推送（如群消息、成员变更等）
- CALLBACK：卡片交互回调等业务回调

## 常见问题
- 获取连接凭证失败：请检查 `AppKey/AppSecret` 是否正确，应用是否开通了机器人 Stream 模式。
- WebSocket 读取超时：属于正常心跳与连接维护行为，SDK 会自动处理，必要时重连。
- 日志输出：默认支持注入任意 PSR-3 Logger；未注入时在 `debug=true` 下打印到标准输出。

## 许可
本项目基于 MIT 开源许可发布，详见 [LICENSE](LICENSE)。
