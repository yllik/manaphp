<?php
namespace ManaPHP\Message\Queue\Adapter;

use ManaPHP\Component;
use ManaPHP\Message\Queue\Adapter\Redis\Exception as RedisException;
use ManaPHP\Message\QueueInterface;

/**
 * Class ManaPHP\Message\Queue\Adapter\Redis
 *
 * @package messageQueue\adapter
 *
 * @property \Redis $messageQueueRedis
 */
class Redis extends Component implements QueueInterface
{
    /**
     * @var string|\ManaPHP\Redis
     */
    protected $_redis = 'redis';

    /**
     * @var string
     */
    protected $_prefix = 'message_queue:';

    /**
     * @var int[]
     */
    protected $_priorities = [self::PRIORITY_HIGHEST, self::PRIORITY_NORMAL, self::PRIORITY_LOWEST];

    /**
     * @var array[]
     */
    protected $_topicKeys = [];

    /**
     * Redis constructor.
     *
     * @param string|array $options
     */
    public function __construct($options = 'redis')
    {
        if (is_string($options)) {
            $this->_redis = $options;
        } elseif (is_object($options)) {
            $this->_redis = $options;
        } else {
            if (isset($options['redis'])) {
                $this->_redis = $options['redis'];
            }

            if (isset($options['prefix'])) {
                $this->_prefix = $options['prefix'];
            }

            if (isset($options['priorities'])) {
                $this->_priorities = (array)$options['priorities'];
            }
        }
    }

    /**
     * @return \ManaPHP\Redis
     */
    protected function _getRedis()
    {
        if (strpos($this->_redis, '/') !== false) {
            return $this->_redis = $this->_dependencyInjector->getInstance('ManaPHP\Redis', [$this->_redis]);
        } else {
            return $this->_redis = $this->_dependencyInjector->getShared($this->_redis);
        }
    }

    /**
     * @param string $topic
     * @param string $body
     * @param int    $priority
     *
     * @throws \ManaPHP\Message\Queue\Adapter\Redis\Exception
     */
    public function push($topic, $body, $priority = self::PRIORITY_NORMAL)
    {
        if (!in_array($priority, $this->_priorities, true)) {
            throw new RedisException('`:priority` priority of `:topic is invalid`', ['priority' => $priority, 'topic' => $topic]);
        }

        $redis = is_object($this->_redis) ? $this->_redis : $this->_getRedis();
        $redis->lPush($this->_prefix . $topic . ':' . $priority, $body);
    }

    /**
     * @param string $topic
     * @param int    $timeout
     *
     * @return string|false
     */
    public function pop($topic, $timeout = PHP_INT_MAX)
    {
        $redis = is_object($this->_redis) ? $this->_redis : $this->_getRedis();
        if (!isset($this->_topicKeys[$topic])) {
            $keys = [];
            foreach ($this->_priorities as $priority) {
                $keys[] = $this->_prefix . $topic . ':' . $priority;
            }

            $this->_topicKeys[$topic] = $keys;
        }
        if ($timeout === 0) {
            foreach ($this->_topicKeys[$topic] as $key) {
                $r = $redis->rPop($key);
                if ($r !== false) {
                    return $r;
                }
            }

            return false;
        } else {
            $r = $redis->brPop($this->_topicKeys[$topic], $timeout);
            return isset($r[1]) ? $r[1] : false;
        }
    }

    /**
     * @param string $topic
     *
     * @return void
     */
    public function delete($topic)
    {
        $redis = is_object($this->_redis) ? $this->_redis : $this->_getRedis();
        foreach ($this->_priorities as $priority) {
            $redis->delete($this->_prefix . $topic . ':' . $priority);
        }
    }

    /**
     * @param string $topic
     * @param int    $priority
     *
     * @return         int
     */
    public function length($topic, $priority = null)
    {
        $redis = is_object($this->_redis) ? $this->_redis : $this->_getRedis();
        if ($priority === null) {
            $length = 0;
            foreach ($this->_priorities as $p) {
                $length += $redis->lLen($this->_prefix . $topic . ':' . $p);
            }

            return $length;
        } else {
            return $redis->lLen($this->_prefix . $topic . ':' . $priority);
        }
    }
}
