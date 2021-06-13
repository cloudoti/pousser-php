<?php

namespace Pousser;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Log implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Log a string.
     *
     * @param string           $msg     The message to log
     * @param array|\Exception $context [optional] Any extraneous information that does not fit well in a string.
     * @param string           $level   [optional] Importance of log message, highly recommended to use Psr\Log\LogLevel::{level}
     *
     * @return void
     */
    public function log($msg, array $context = array(), $level = LogLevel::INFO)
    {
        if (is_null($this->logger)) {
            return;
        }
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->log($level, $msg, $context);
            return;
        }
        // Support old style logger (deprecated)
        $msg = sprintf('Pousser: %s: %s', strtoupper($level), $msg);
        $replacement = array();
        foreach ($context as $k => $v) {
            $replacement['{'.$k.'}'] = $v;
        }
        $this->logger->log(strtr($msg, $replacement));
    }

}
