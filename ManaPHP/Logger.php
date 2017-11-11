<?php

namespace ManaPHP;

use ManaPHP\Logger\Exception as LoggerException;

/**
 * Class ManaPHP\Logger
 *
 * @package logger
 */
class Logger extends Component implements LoggerInterface
{
    const LEVEL_FATAL = 'FATAL';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARN = 'WARN';
    const LEVEL_INFO = 'INFO';
    const LEVEL_DEBUG = 'DEBUG';

    /**
     * @var array
     */
    protected $_s2i;

    /**
     * @var array
     */
    protected $_appenders;

    /**
     * @var string
     */
    protected $_category;

    /**
     * @var array
     */
    protected $_filter = [];

    /**
     * Logger constructor.
     *
     * @param string|array|\ManaPHP\Logger\AppenderInterface $options
     *
     * @throws \ManaPHP\Logger\Exception
     */
    public function __construct($options = [])
    {
        $this->_s2i = array_flip([self::LEVEL_FATAL, self::LEVEL_ERROR, self::LEVEL_WARN, self::LEVEL_INFO, self::LEVEL_DEBUG]);

        if (is_string($options)) {
            $options = ['appenders' => [[['class' => $options]]]];
        }

        if (isset($options['appenders'])) {
            foreach ($options['appenders'] as $name => $appender) {
                if (isset($appender['filter'])) {
                    $filter = $this->_normalizeFilter($appender['filter']);
                    unset($appender['filter']);
                } else {
                    $filter = [];
                }

                $this->_appenders[$name] = ['filter' => $filter, 'appender' => $appender];
            }
        }

        if (isset($options['filter'])) {
            $this->_filter = $this->_normalizeFilter($options['filter']);
        }
    }

    /**
     * @param array $filter
     *
     * @return array
     * @throws \ManaPHP\Logger\Exception
     */
    protected function _normalizeFilter($filter)
    {
        if (isset($filter['level'])) {
            $level = strtoupper($filter['level']);
            if (!isset($this->_s2i[$level])) {
                throw new LoggerException('`:level` level is invalid', ['level' => $filter['level']]);
            } else {
                $filter['level'] = $this->_s2i[$level];
            }
        }

        if (isset($filter['categories'])) {
            $filter['categories'] = (array)$filter['categories'];
        }

        return $filter;
    }

    /**
     * @param string $category
     */
    public function setCategory($category)
    {
        $this->_category = $category;
    }

    /**
     * @return array
     */
    public function getLevels()
    {
        return $this->_s2i;
    }

    /**
     * @param array $traces
     *
     * @return string
     */
    protected function _getLocation($traces)
    {
        if (isset($traces[2]['function']) && !isset($this->_s2i[strtoupper($traces[2]['function'])])) {
            $trace = $traces[1];
        } else {
            $trace = $traces[2];
        }

        if (isset($trace['file'], $trace['line'])) {
            return str_replace($this->alias->get('@app'), '', strtr($trace['file'], '\\', '/')) . ':' . $trace['line'];
        }

        return '';
    }

    /**
     * @param array[] $traces
     *
     * @return string
     */
    protected function _getCaller($traces)
    {
        if (isset($traces[2]['function']) && !isset($this->_s2i[strtoupper($traces[2]['function'])])) {
            $trace = $traces[2];
        } elseif (isset($traces[3])) {
            $trace = $traces[3];
        } else {
            return '';
        }

        if (isset($trace['class'], $trace['type'], $trace['function'])) {
            return $trace['class'] . $trace['type'] . $trace['function'];
        }

        return '';
    }

    /**
     * @param array $filter
     * @param array $logEvent
     *
     * @return bool
     */
    protected function _IsFiltered($filter, $logEvent)
    {
        if (isset($filter['level']) && $filter['level'] < $this->_s2i[$logEvent['level']]) {
            return true;
        }

        if (isset($filter['categories'])) {
            foreach ((array)$filter['categories'] as $category) {
                if (fnmatch($category, $logEvent['category'])) {
                    break;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * @param string       $level
     * @param string|array $message
     * @param string       $category
     *
     * @return static
     */
    public function log($level, $message, $category = null)
    {
        if (isset($this->_filter['level']) && $this->_filter['level'] < $this->_s2i[$level]) {
            return $this;
        }

        if (is_array($message)) {
            $replaces = [];
            /** @noinspection ForeachSourceInspection */
            foreach ($message as $k => $v) {
                if ($k !== 0) {
                    $replaces['{' . $k . '}'] = $v;
                }
            }

            $message = strtr($message[0], $replaces);
        }

        $traces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        $logEvent = [];

        $logEvent['level'] = $level;
        $logEvent['message'] = $message;
        $logEvent['category'] = $category ?: $this->_category;
        $logEvent['timestamp'] = time();
        $logEvent['location'] = $this->_getLocation($traces);
        $logEvent['caller'] = $this->_getCaller($traces);

        $this->fireEvent('logger:log', $logEvent);

        /**
         * @var \ManaPHP\Logger\AppenderInterface $appender
         */
        foreach ($this->_appenders as $name => $appender_conf) {
            if (!$this->_IsFiltered($appender_conf['filter'], $logEvent)) {
                $appender = $this->_dependencyInjector->getShared($appender_conf['appender']);
                $appender->append($logEvent);
            }
        }

        return $this;
    }

    /**
     * Sends/Writes a debug message to the log
     *
     * @param string|array $message
     * @param string       $category
     *
     * @return static
     */
    public function debug($message, $category = null)
    {
        return $this->log(self::LEVEL_DEBUG, $message, $category);
    }

    /**
     * Sends/Writes an info message to the log
     *
     * @param string|array $message
     * @param string       $category
     *
     * @return static
     */
    public function info($message, $category = null)
    {
        return $this->log(self::LEVEL_INFO, $message, $category);
    }

    /**
     * Sends/Writes a warning message to the log
     *
     * @param string|array $message
     * @param string       $category
     *
     * @return static
     */
    public function warn($message, $category = null)
    {
        return $this->log(self::LEVEL_WARN, $message, $category);
    }

    /**
     * Sends/Writes an error message to the log
     *
     * @param string|array $message
     * @param    string    $category
     *
     * @return static
     */
    public function error($message, $category = null)
    {
        return $this->log(self::LEVEL_ERROR, $message, $category);
    }

    /**
     * Sends/Writes a critical message to the log
     *
     * @param string|array $message
     * @param string       $category
     *
     * @return static
     */
    public function fatal($message, $category = null)
    {
        return $this->log(self::LEVEL_FATAL, $message, $category);
    }
}