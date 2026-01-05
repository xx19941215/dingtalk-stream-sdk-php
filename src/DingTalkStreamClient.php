<?php

namespace Xx19941215\DingTalkStream;

use WebSocket\Client as WebSocketClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Exception;

class DingTalkStreamClient
{
    /**
     * 钉钉 Stream 客户端
     */
    private ?WebSocketClient $wsClient = null;

    /**
     * 钉钉应用 ID
     */
    private string $clientId;

    /**
     * 钉钉应用 Secret
     */
    private string $clientSecret;

    /**
     * 订阅配置
     */
    private array $subscriptions;

    /**
     * 用户代理
     */
    private string $userAgent;

    /**
     * 本地 IP
     */
    private string $localIp = '';

    /**
     * 调试模式
     */
    private bool $debug = false;

    /**
     * WebSocket 连接端点
     */
    private string $endpoint = '';

    /**
     * WebSocket 连接票证
     */
    private string $ticket = '';

    /**
     * 事件处理器
     */
    private array $handlers = [];

    /**
     * 连接状态
     */
    private bool $isConnected = false;

    /**
     * 最后 ping 时间
     */
    private int $lastPingTime = 0;

    /**
     * 钉钉 Stream API 地址
     */
    private const API_URL = 'https://api.dingtalk.com/v1.0/gateway/connections/open';

    /**
     * 最大错误次数
     */
    private const MAX_ERRORS = 5;

    /**
     * 错误恢复延迟（秒）
     */
    private const ERROR_RETRY_DELAY = 2;

    /**
     * HTTP 客户端
     */
    private HttpClient $httpClient;

    /**
     * 日志记录器
     */
    private ?LoggerInterface $logger;

    /**
     * 构造函数
     *
     * @param array $config 配置信息，包含 clientId、clientSecret、subscriptions、ua、localIp、debug
     * @param HttpClient|null $httpClient 可选自定义 HTTP 客户端
     * @param LoggerInterface|null $logger 可选日志记录器（PSR-3）
     * @throws Exception
     */
    public function __construct(array $config, ?HttpClient $httpClient = null, ?LoggerInterface $logger = null)
    {
        $this->clientId = $config['clientId'] ?? '';
        $this->clientSecret = $config['clientSecret'] ?? '';
        $this->subscriptions = $config['subscriptions'] ?? [
            ['type' => 'EVENT', 'topic' => '*']
        ];
        $this->userAgent = $config['ua'] ?? $config['userAgent'] ?? 'dingtalk-stream-php/1.0.0';
        $this->localIp = $config['localIp'] ?? '';
        $this->debug = (bool)($config['debug'] ?? false);
        $this->httpClient = $httpClient ?? new HttpClient([
            'timeout' => 10.0,
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Content-Type' => 'application/json'
            ]
        ]);
        $this->logger = $logger;

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new Exception('钉钉配置不完整，缺少 clientId 或 clientSecret');
        }
    }

    /**
     * 注册事件处理器
     *
     * @param string $type 事件类型：EVENT, CALLBACK
     * @param callable $handler 处理函数
     * @return void
     */
    public function registerHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * 开始连接并开始监听
     *
     * @return void
     * @throws Exception
     */
    public function connect(): void
    {
        $this->log('开始连接到钉钉 Stream 服务...');

        try {
            if (!$this->getConnectionCredentials()) {
                throw new Exception('获取连接凭证失败');
            }

            $this->establishWebSocketConnection();
            $this->messageLoop();
        } catch (Exception $e) {
            $this->logError('连接过程中出错: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取连接凭证
     *
     * @return bool
     */
    private function getConnectionCredentials(): bool
    {
        $data = [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'subscriptions' => $this->subscriptions,
            'ua' => $this->userAgent
        ];

        if (!empty($this->localIp)) {
            $data['localIp'] = $this->localIp;
        }

        try {
            $response = $this->httpClient->post(self::API_URL, [
                'json' => $data
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logError(sprintf('获取连接凭证失败: HTTP %d', $status));
                return false;
            }

            $body = (string)$response->getBody();
            $result = json_decode($body, true);
            $this->endpoint = $result['endpoint'] ?? '';
            $this->ticket = $result['ticket'] ?? '';

            if (empty($this->endpoint) || empty($this->ticket)) {
                $this->logError('钉钉服务返回的凭证不完整');
                return false;
            }

            $this->log('成功获取连接凭证');
            return true;
        } catch (GuzzleException $e) {
            $this->logError("获取连接凭证异常: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 建立 WebSocket 连接
     *
     * @return void
     * @throws Exception
     */
    private function establishWebSocketConnection(): void
    {
        $url = "{$this->endpoint}?ticket={$this->ticket}";

        try {
            $this->wsClient = new WebSocketClient($url);
            $this->isConnected = true;
            $this->log('WebSocket 连接已建立');
        } catch (Exception $e) {
            $this->logError("WebSocket 连接失败: {$e->getMessage()}");
            throw new Exception("WebSocket 连接失败: {$e->getMessage()}");
        }
    }

    /**
     * 消息接收循环
     *
     * @return void
     */
    private function messageLoop(): void
    {
        $errorCount = 0;

        while ($this->isConnected) {
            try {
                $data = $this->receiveData();
                if ($data === false) {
                    $this->log('未收到数据，准备重新连接...');
                    break;
                }

                $this->handleMessage($data);
                $errorCount = 0;
            } catch (Exception $e) {
                $errorCount++;
                $this->logError("消息处理错误 (第 {$errorCount}/" . self::MAX_ERRORS . ' 次): ' . $e->getMessage());

                if ($errorCount >= self::MAX_ERRORS) {
                    $this->log('达到最大错误次数，准备重新连接...');
                    break;
                }

                sleep(self::ERROR_RETRY_DELAY);
            }
        }
    }

    /**
     * 接收 WebSocket 数据
     *
     * @return string|bool
     * @throws Exception
     */
    private function receiveData()
    {
        try {
            if (!$this->wsClient) {
                throw new Exception('WebSocket 客户端未初始化');
            }
            $message = $this->wsClient->receive();
            return $message;
        } catch (Exception $e) {
            if ($this->isTimeoutError($e->getMessage())) {
                $this->log('WebSocket 读取超时（这是正常的）');
                return false;
            }
            throw new Exception("接收数据失败: {$e->getMessage()}");
        }
    }

    /**
     * 处理接收到的消息
     *
     * @param string $data 原始消息字符串
     * @return void
     */
    private function handleMessage(string $data): void
    {
        $message = json_decode($data, true);
        if (!is_array($message)) {
            $this->logError('无效的消息格式');
            return;
        }

        $type = $message['type'] ?? '';
        $headers = $message['headers'] ?? [];
        $topic = $headers['topic'] ?? '';

        switch ($type) {
            case 'SYSTEM':
                $this->handleSystemMessage($topic, $message);
                break;
            case 'EVENT':
            case 'CALLBACK':
                $this->handleBusinessMessage($type, $message);
                break;
            default:
                $this->logError("未知的消息类型: {$type}");
        }
    }

    /**
     * 处理系统消息
     *
     * @param string $topic 系统消息主题
     * @param array $message 解码后的消息数组
     * @return void
     */
    private function handleSystemMessage(string $topic, array $message): void
    {
        switch ($topic) {
            case 'ping':
                $this->log('收到 ping 消息');
                $this->lastPingTime = time();
                $this->sendPong($message);
                break;
            case 'disconnect':
                $this->log('收到断开连接请求');
                $this->handleDisconnect();
                break;
            default:
                $this->log("收到未知的系统消息: {$topic}");
        }
    }

    /**
     * 处理业务消息
     *
     * @param string $type 消息类型：EVENT 或 CALLBACK
     * @param array $message 解码后的消息数组
     * @return void
     */
    private function handleBusinessMessage(string $type, array $message): void
    {
        if (!isset($this->handlers[$type])) {
            $this->logError("未注册类型为 '{$type}' 的处理器");
            return;
        }

        try {
            $result = call_user_func($this->handlers[$type], $message);
            $messageId = $message['headers']['messageId'] ?? '';
            if (!empty($messageId)) {
                $this->sendResponse($messageId, is_array($result) ? $result : ['result' => $result]);
            }
        } catch (Exception $e) {
            $this->logError("处理 {$type} 消息时出错: {$e->getMessage()}");
        }
    }

    /**
     * 发送 pong 响应
     *
     * @param array $pingMessage 原始 ping 消息
     * @return void
     */
    private function sendPong(array $pingMessage): void
    {
        $response = [
            'code' => 200,
            'headers' => [
                'contentType' => 'application/json',
                'messageId' => $pingMessage['headers']['messageId'] ?? ''
            ],
            'message' => 'OK',
            'data' => $pingMessage['data'] ?? []
        ];

        $this->sendData(json_encode($response));
    }

    /**
     * 处理断开连接并尝试重连
     *
     * @return void
     */
    private function handleDisconnect(): void
    {
        $this->isConnected = false;

        try {
            if ($this->wsClient) {
                $this->wsClient->close();
            }
        } catch (Exception $e) {
            $this->logError("关闭 WebSocket 连接时出错: {$e->getMessage()}");
        }

        $this->connect();
    }

    /**
     * 发送响应消息
     *
     * @param string $messageId 原始消息的 messageId
     * @param array $result 业务处理结果
     * @return void
     */
    private function sendResponse(string $messageId, array $result): void
    {
        $response = [
            'code' => 200,
            'headers' => [
                'contentType' => 'application/json',
                'messageId' => $messageId
            ],
            'message' => 'OK',
            'data' => json_encode($result)
        ];

        $this->sendData(json_encode($response));
    }

    /**
     * 发送数据到 WebSocket
     *
     * @param string $data 序列化后的字符串数据
     * @return void
     * @throws Exception
     */
    private function sendData(string $data): void
    {
        try {
            if (!$this->wsClient) {
                throw new Exception('WebSocket 客户端未初始化');
            }
            $this->wsClient->send($data);
        } catch (Exception $e) {
            $this->logError("发送数据失败: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * 判断是否是超时错误
     *
     * @param string $errorMessage 异常消息文本
     * @return bool
     */
    private function isTimeoutError(string $errorMessage): bool
    {
        return stripos($errorMessage, 'timeout') !== false ||
               stripos($errorMessage, 'read timeout') !== false ||
               stripos($errorMessage, 'timed out') !== false;
    }

    /**
     * 记录信息日志或输出
     *
     * @param string $message 日志消息
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->info('[DingTalk Stream] ' . $message);
            return;
        }

        if ($this->debug) {
            echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        }
    }

    /**
     * 记录错误日志或输出
     *
     * @param string $message 错误消息
     * @return void
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error('[DingTalk Stream] ' . $message);
            return;
        }

        if ($this->debug) {
            echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL;
        }
    }
}
