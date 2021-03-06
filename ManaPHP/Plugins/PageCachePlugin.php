<?php
namespace ManaPHP\Plugins;

use Closure;
use ManaPHP\Event\EventArgs;
use ManaPHP\Exception\AbortException;
use ManaPHP\Exception\MissingFieldException;
use ManaPHP\Plugin;

class PageCachePluginContext
{
    public $ttl;
    public $key;
    public $if_none_match;
    public $cache_used;
}

/**
 * Class PageCachePlugin
 * @package ManaPHP\Plugins
 * @property-read \ManaPHP\Plugins\PageCachePluginContext $_context
 */
class PageCachePlugin extends Plugin
{
    /**
     * @var bool
     */
    protected $_enabled = true;

    /**
     * @var string
     */
    protected $_prefix;

    /**
     * PageCachePlugin constructor.
     *
     * @param array $options
     */
    public function __construct($options = [])
    {
        if (isset($options['enabled'])) {
            $this->_enabled = (bool)$options['enabled'];
        }

        $this->_prefix = $options['prefix'] ?? "cache:{$this->configure->id}:pageCachePlugin:";

        if ($this->_enabled) {
            $this->attachEvent('request:ready', [$this, 'onRequestReady']);
            $this->attachEvent('response:sending', [$this, 'onResponseSending']);
        }
    }

    public function onRequestReady(EventArgs $eventArgs)
    {
        if (!in_array($this->request->getMethod(), ['GET', 'POST', 'HEAD'])) {
            return;
        }

        /** @var \ManaPHP\DispatcherInterface $dispatcher */
        /** @var \ManaPHP\Http\Controller $controller */
        $dispatcher = $eventArgs->source;
        $controller = $eventArgs->data['controller'];
        $action = $eventArgs->data['action'];

        $pageCache = $controller->getPageCache();
        if ($pageCache === [] || ($pageCache = $pageCache[$action] ?? false) === false) {
            return;
        }

        $context = $this->_context;

        $key = '';
        if (is_int($pageCache)) {
            $context->ttl = $pageCache;
        } elseif (is_array($pageCache)) {
            if (!isset($pageCache['ttl'])) {
                throw new MissingFieldException('ttl');
            }
            $context->ttl = $pageCache['ttl'];

            if (isset($pageCache['key'])) {
                $key = $pageCache['key'];

                if ($key instanceof Closure) {
                    $key = $key();
                } else {
                    $params = [];
                    foreach ((array)$pageCache['key'] as $k => $v) {
                        if (is_int($k)) {
                            $params[$v] = input($v, '');
                        } else {
                            $params[$k] = $v;
                        }
                    }
                    $key = http_build_query($params);
                }
            }
        }

        if ($key === '') {
            $context->key = $this->_prefix . $dispatcher->getPath();
        } else {
            $context->key = $this->_prefix . $dispatcher->getPath() . ':' . $key;
        }

        $context->if_none_match = $this->request->getServer('HTTP_IF_NONE_MATCH');

        if (($etag = $this->redisCache->hGet($context->key, 'etag')) === false) {
            return;
        }

        if ($etag === $context->if_none_match) {
            $this->response->setNotModified();
            throw new AbortException();
        }

        if (!$cache = $this->redisCache->hGetAll($context->key)) {
            return;
        }

        $this->response->setETag($cache['etag']);
        $this->response->setMaxAge(max($this->redisCache->ttl($context->key), 1));

        $this->response->setContentType($cache['content-type']);

        if (strpos($this->request->hasServer('HTTP_ACCEPT_ENCODING'), 'gzip') !== false) {
            $this->response->setHeader('Content-Encoding', 'gzip');
            $this->response->setContent($cache['content']);
        } else {
            $this->response->setContent(gzdecode($cache['content']));
        }
        $context->cache_used = true;

        throw new AbortException();
    }

    public function onResponseSending(EventArgs $eventArgs)
    {
        $context = $this->_context;

        if ($context->cache_used === true || $context->ttl === null || $context->ttl <= 0) {
            return;
        }

        /** @var \ManaPHP\Http\ResponseContext $response */
        $response = $eventArgs->data['response'];
        if ($response->status_code !== 200) {
            return;
        }

        $etag = strlen($response->content) . '-' . md5($response->content);

        $this->redisCache->hMSet($context->key, [
            'ttl' => $context->ttl,
            'etag' => $etag,
            'content-type' => $response->headers['Content-Type'],
            'content' => gzencode($response->content)]);
        $this->redisCache->expire($context->key, $context->ttl);

        if ($context->if_none_match === $etag) {
            $this->response->setNotModified();
        } else {
            $this->response->setMaxAge($context->ttl);
            $this->response->setETag($etag);
        }
    }
}
