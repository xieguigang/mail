<?php
/**
 * smtpd.php —— SMTP 收信守护进程
 *
 * 监听 25 端口接收来自互联网的真实邮件，解析后落库归档。
 *
 * 启动方式：
 *   php daemon/smtpd.php
 *
 * 架构选型：非阻塞 socket + socket_select() 单进程多路复用，
 * 而非「每连接 fork 一个子进程」。
 * 理由：pcntl 扩展在 Windows 下不可用，多路复用方案跨平台且无进程开销，
 * 单进程即可稳定支撑数十个并发连接（邮件服务器的典型并发量）。
 *
 * socket 创建与 accept 循环的写法参照框架
 * Framework/php/websocket/SocketServer.php 的实现。
 */

require_once __DIR__ . "/bootstrap_cli.php";

class SmtpDaemon
{
    /** @var resource 监听 socket */
    private $master;

    /** @var array 全部 socket（含监听 socket），供 socket_select 使用 */
    private $sockets = [];

    /** @var SmtpSession[] 连接会话，键为 socket 的字符串标识 */
    private $sessions = [];

    /** @var boolean 运行标志，收到退出信号时置为 false */
    private $running = true;

    /**
     * 启动服务
     *
     * @return void
     */
    public function run()
    {
        $host = DotNetRegistry::Read("SMTPD_BIND", "0.0.0.0");
        $port = (int) DotNetRegistry::Read("SMTPD_PORT", 25);
        $maxConn = (int) DotNetRegistry::Read("SMTPD_MAX_CONN", 64);

        if (!extension_loaded("sockets")) {
            cli_echo("FATAL: the 'sockets' extension is required but not loaded.");
            cli_echo("Please enable extension=sockets in php.ini");
            exit(1);
        }

        # ---- 创建监听 socket ----
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->master === false) {
            cli_echo("FATAL: failed to create socket: " . socket_strerror(socket_last_error()));
            exit(1);
        }

        # SO_REUSEADDR：允许进程重启后立即重新绑定端口，
        # 否则 TIME_WAIT 状态会导致「地址已被占用」错误
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->master, $host, $port) === false) {
            $err = socket_strerror(socket_last_error($this->master));
            cli_echo("FATAL: failed to bind " . $host . ":" . $port . " - " . $err);

            if ($port < 1024) {
                cli_echo("HINT: binding to a port below 1024 requires administrator/root privileges.");
            }

            exit(1);
        }

        if (socket_listen($this->master, SOMAXCONN) === false) {
            cli_echo("FATAL: failed to listen: " . socket_strerror(socket_last_error($this->master)));
            exit(1);
        }

        # 监听 socket 设为非阻塞，使 accept 不会卡住主循环
        socket_set_nonblock($this->master);

        $this->sockets[] = $this->master;

        cli_echo("SMTP server listening on " . $host . ":" . $port);
        cli_echo("Serving domains: " . implode(", ", MailAddress::localDomains()));
        cli_echo("Max connections: " . $maxConn . ", max message size: "
            . DotNetRegistry::Read("SMTPD_MAX_SIZE", 104857600) . " bytes");

        $this->installSignalHandlers();

        # ---- 主循环 ----
        while ($this->running) {
            $this->loopOnce($maxConn);
        }

        $this->shutdown();
    }

    /**
     * 单次事件循环
     *
     * @param integer $maxConn
     * @return void
     */
    private function loopOnce($maxConn)
    {
        # socket_select 会修改传入的数组，因此必须传副本
        $read = $this->sockets;
        $write = null;
        $except = null;

        # 1 秒超时：即使无事件也能定期执行超时回收与信号处理
        $result = @socket_select($read, $write, $except, 1);

        if ($result === false) {
            # 被信号中断属于正常现象，继续循环
            return;
        }

        if ($result > 0) {
            foreach ($read as $socket) {
                if ($socket === $this->master) {
                    $this->acceptConnection($maxConn);
                } else {
                    $this->handleClient($socket);
                }
            }
        }

        # 每轮回收超时连接
        $this->reapIdleConnections();

        # 处理挂起的信号（如 Ctrl+C）
        if (function_exists("pcntl_signal_dispatch")) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * 接受新连接
     *
     * @param integer $maxConn
     * @return void
     */
    private function acceptConnection($maxConn)
    {
        $client = @socket_accept($this->master);

        if ($client === false) {
            return;
        }

        # 超过连接数上限：礼貌拒绝并关闭，避免资源耗尽
        if (count($this->sessions) >= $maxConn) {
            @socket_write($client, "421 Too many connections, try again later\r\n");
            @socket_close($client);
            return;
        }

        socket_set_nonblock($client);

        $remoteIp = "";
        @socket_getpeername($client, $remoteIp);

        $key = $this->keyOf($client);
        $this->sockets[] = $client;
        $this->sessions[$key] = new SmtpSession($client, $remoteIp);

        $this->sessions[$key]->greet();

        cli_echo("connection from " . $remoteIp . " (active: " . count($this->sessions) . ")");
    }

    /**
     * 处理客户端数据
     *
     * @param resource $socket
     * @return void
     */
    private function handleClient($socket)
    {
        $key = $this->keyOf($socket);

        if (!isset($this->sessions[$key])) {
            $this->closeConnection($socket);
            return;
        }

        $session = $this->sessions[$key];

        # 每次最多读取 64KB。DATA 阶段的大邮件会分多次读取，
        # 每次读到的内容立即写盘，因此内存占用恒定
        $data = @socket_read($socket, 65536, PHP_BINARY_READ);

        # 读到 false 或空串表示对端已关闭连接
        if ($data === false || $data === "") {
            $this->closeConnection($socket);
            return;
        }

        try {
            $session->feed($data);
        } catch (Exception $ex) {
            mail_log("smtpd", "session error from " . $session->remoteIp . ": " . $ex->getMessage());
            cli_echo("session error: " . $ex->getMessage());
            $session->reply(451, "Internal server error");
            $this->closeConnection($socket);
            return;
        }

        # 会话正常结束（QUIT 或错误过多）
        if ($session->closed) {
            $this->closeConnection($socket);
        }
    }

    /**
     * 回收空闲超时的连接
     *
     * @return void
     */
    private function reapIdleConnections()
    {
        $timeout = (int) DotNetRegistry::Read("SMTPD_IDLE_TIMEOUT", 300);
        $now = time();

        foreach ($this->sessions as $key => $session) {
            if ($now - $session->lastActive > $timeout) {
                $session->reply(421, "Idle timeout, closing connection");
                $this->closeConnection($session->socket);
            }
        }
    }

    /**
     * 关闭一个连接并清理其资源
     *
     * @param resource $socket
     * @return void
     */
    private function closeConnection($socket)
    {
        $key = $this->keyOf($socket);

        if (isset($this->sessions[$key])) {
            # 清理会话可能残留的临时文件
            $this->sessions[$key]->cleanup();
            unset($this->sessions[$key]);
        }

        # 从待监听列表中移除
        foreach ($this->sockets as $i => $s) {
            if ($s === $socket) {
                unset($this->sockets[$i]);
                break;
            }
        }

        # 重建索引，保证 socket_select 能正确处理数组
        $this->sockets = array_values($this->sockets);

        if (is_resource($socket) || $socket instanceof Socket) {
            @socket_close($socket);
        }
    }

    /**
     * 生成 socket 的唯一键
     *
     * PHP 8 起 socket 是对象而非资源，需分别处理。
     *
     * @param resource|object $socket
     * @return string
     */
    private function keyOf($socket)
    {
        if (is_object($socket)) {
            return spl_object_hash($socket);
        }

        return (string) (int) $socket;
    }

    /**
     * 注册退出信号处理器，支持优雅停机
     *
     * @return void
     */
    private function installSignalHandlers()
    {
        if (!function_exists("pcntl_signal")) {
            # Windows 下 pcntl 不可用，只能用 Ctrl+C 强制中断
            return;
        }

        $handler = function ($signal) {
            cli_echo("received signal " . $signal . ", shutting down gracefully...");
            $this->running = false;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /**
     * 停机清理
     *
     * @return void
     */
    private function shutdown()
    {
        cli_echo("closing " . count($this->sessions) . " active connection(s)...");

        foreach ($this->sessions as $session) {
            $session->reply(421, "Server shutting down");
            $session->cleanup();

            if (is_resource($session->socket) || $session->socket instanceof Socket) {
                @socket_close($session->socket);
            }
        }

        $this->sessions = [];

        if ($this->master !== null) {
            @socket_close($this->master);
        }

        cli_echo("SMTP server stopped.");
    }
}

$daemon = new SmtpDaemon();
$daemon->run();
