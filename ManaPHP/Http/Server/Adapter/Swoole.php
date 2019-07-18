<?php
namespace ManaPHP\Http\Server\Adapter;

use ManaPHP\ContextManager;
use ManaPHP\Http\Server;
use Swoole\Runtime;
use Throwable;

class SwooleContext
{
    /**
     * @var \Swoole\Http\Request
     */
    public $request;

    /**
     * @var \Swoole\Http\Response
     */
    public $response;
}

/**
 * Class Server
 * @package ManaPHP\Http\Server
 * @property-read \ManaPHP\RouterInterface              $router
 * @property \ManaPHP\Http\Server\Adapter\SwooleContext $_context
 */
class Swoole extends Server
{
    /**
     * @var array
     */
    protected $_settings = [];

    /**
     * @var \Swoole\Http\Server
     */
    protected $_swoole;

    /**
     * @var \ManaPHP\Http\Server\HandlerInterface
     */
    protected $_handler;

    /**
     * Swoole constructor.
     *
     * @param array $options
     */
    public function __construct($options = [])
    {
        parent::__construct($options);

        $script_filename = get_included_files()[0];
        $_SERVER = [
            'DOCUMENT_ROOT' => dirname($script_filename),
            'SCRIPT_FILENAME' => $script_filename,
            'SCRIPT_NAME' => '/' . basename($script_filename),
            'SERVER_ADDR' => $this->_getLocalIp(),
            'SERVER_PORT' => $this->_port,
            'SERVER_SOFTWARE' => 'Swoole/' . SWOOLE_VERSION . ' PHP/' . PHP_VERSION,
            'PHP_SELF' => '/' . basename($script_filename),
            'QUERY_STRING' => '',
            'REQUEST_SCHEME' => 'http',
        ];

        unset($_GET, $_POST, $_REQUEST, $_FILES, $_COOKIE);

        $this->alias->set('@web', '');
        $this->alias->set('@asset', '');

        $options['enable_coroutine'] = MANAPHP_COROUTINE ? true : false;

        if (isset($options['max_request']) && $options['max_request'] < 1) {
            $options['max_request'] = 1;

            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
        }

        $this->_settings = $options;
    }

    /**
     * @return string
     */
    protected function _getLocalIp()
    {
        if ($this->_host !== '0.0.0.0') {
            return $this->_host;
        }

        $ips = swoole_get_local_ip();
        if (!$ips) {
            return '127.0.0.1';
        } elseif (isset($ips['eth0'])) {
            return $ips['eth0'];
        } elseif (isset($ips['ens33'])) {
            return $ips['ens33'];
        } elseif (isset($ips['ens1'])) {
            return $ips['ens1'];
        } else {
            foreach ($ips as $name => $ip) {
                if ($name === 'docker' || strpos($name, 'br-') === 0) {
                    continue;
                }

                return $ip;
            }
            return current($ips);
        }
    }

    /**
     * @param \Swoole\Http\Request $request
     */
    protected function _prepareGlobals($request)
    {
        $_server = array_change_key_case($request->server, CASE_UPPER);
        unset($_server['SERVER_SOFTWARE']);

        foreach ($request->header ?: [] as $k => $v) {
            if (in_array($k, ['content-type', 'content-length'], true)) {
                $_server[strtoupper(strtr($k, '-', '_'))] = $v;
            } else {
                $_server['HTTP_' . strtoupper(strtr($k, '-', '_'))] = $v;
            }
        }

        /** @noinspection AdditionOperationOnArraysInspection */
        $_server += $_SERVER;

        $_get = $request->get ?: [];
        $request_uri = $_server['REQUEST_URI'];
        $_get['_url'] = ($pos = strpos($request_uri, '?')) ? substr($request_uri, 0, $pos) : $request_uri;

        $_post = $request->post ?: [];

        if (!$_post && isset($_server['REQUEST_METHOD']) && !in_array($_server['REQUEST_METHOD'], ['GET', 'OPTIONS'], true)) {
            $data = $request->rawContent();

            if (isset($_server['CONTENT_TYPE']) && strpos($_server['CONTENT_TYPE'], 'application/json') !== false) {
                $_post = json_decode($data, true, 16);
            } else {
                parse_str($data, $_post);
            }
            if (!is_array($_post)) {
                $_post = [];
            }
        }

        if (!isset($_server['HTTP_X_REQUEST_ID'])) {
            $_server['HTTP_X_REQUEST_ID'] = 'aa' . bin2hex(random_bytes(15));
        }

        $globals = $this->request->getGlobals();

        $globals->_GET = $_get;
        $globals->_POST = $_post;
        /** @noinspection AdditionOperationOnArraysInspection */
        $globals->_REQUEST = $_post + $_get;
        $globals->_SERVER = $_server;
        $globals->_COOKIE = $request->cookie ?: [];
        $globals->_FILES = $request->files ?: [];

        if ($this->_compatible_globals) {
            $_GET = $globals->_GET;
            $_POST = $globals->_POST;
            $_REQUEST = $globals->_REQUEST;
            $_SERVER = $globals->_SERVER;
            $_COOKIE = $globals->_COOKIE;
            $_FILES = $globals->_FILES;
        }
    }

    /**
     * @param \ManaPHP\Http\Server\HandlerInterface $handler
     *
     * @return static
     */
    public function start($handler)
    {
        echo PHP_EOL, str_repeat('+', 80), PHP_EOL;

        if (!empty($this->_settings['enable_static_handler'])) {
            $this->_settings['document_root'] = $_SERVER['DOCUMENT_ROOT'];
        }

        $this->_swoole = new \Swoole\Http\Server($this->_host, $this->_port);
        $this->_swoole->set($this->_settings);
        $this->_handler = $handler;

        if ($this->_settings['enable_coroutine']) {
            Runtime::enableCoroutine();
        }

        $this->log('info',
            sprintf('starting listen on: %s:%d with setting: %s', $this->_host, $this->_port, json_encode($this->_settings, JSON_UNESCAPED_SLASHES)));
        $this->log('info', 'http://' . $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'] . ($this->router->getPrefix() ?: '/'));
        $this->_swoole->on('request', [$this, 'onRequest']);

        $this->_swoole->start();
        echo sprintf('[%s][info]: shutdown', date('c')), PHP_EOL;

        return $this;
    }

    /**
     * @param \Swoole\Http\Request  $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest($request, $response)
    {
        try {
            if ($request->server['request_uri'] === '/favicon.ico') {
                $response->status(404);
                $response->end();
                return;
            }
            $context = $this->_context;

            $context->request = $request;
            $context->response = $response;

            $this->_prepareGlobals($request);

            $this->_handler->handle();

        } catch (Throwable $exception) {
            $str = date('c') . ' ' . get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL;
            $str .= '    at ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
            $traces = $exception->getTraceAsString();
            $str .= preg_replace('/#\d+\s/', '    at ', $traces);
            echo $str . PHP_EOL;
        } finally {
            ContextManager::reset();
        }
    }

    /**
     * @param \ManaPHP\Http\ResponseInterface $response
     */
    public function send($response)
    {
        $this->eventsManager->fireEvent('response:beforeSend', $this, $response);

        /** @var \ManaPHP\Http\Response $response */
        $response_context = $response->_context;
        $sw_response = $this->_context->response;

        $sw_response->status($response_context->status_code);

        foreach ($response_context->headers as $name => $value) {
            $sw_response->header($name, $value, false);
        }

        $server = $this->request->getGlobals()->_SERVER;

        $sw_response->header('X-Request-Id', $server['HTTP_X_REQUEST_ID'], false);
        $sw_response->header('X-Response-Time', sprintf('%.3f', microtime(true) - $server['REQUEST_TIME_FLOAT']), false);

        foreach ($response_context->cookies as $cookie) {
            $sw_response->cookie($cookie['name'], $cookie['value'], $cookie['expire'],
                $cookie['path'], $cookie['domain'], $cookie['secure'],
                $cookie['httpOnly']);
        }

        if ($response_context->file) {
            $sw_response->sendfile($this->alias->resolve($response_context->file));
        } else {
            $sw_response->end($response_context->content);
        }

        $this->eventsManager->fireEvent('response:afterSend', $this, $response);
    }

    public function __debugInfo()
    {
        $data = parent::__debugInfo();
        unset($data['_swoole']);

        return $data;
    }
}