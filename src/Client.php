<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Stomp;

use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Lib\Timer;

/**
 * Class Client
 * @package Workerman\Stomp
 */
class Client
{
    /**
     * STATE_INITIAL.
     */
    const STATE_INITIAL = 1;

    /**
     * STATE_CONNECTING
     */
    const STATE_CONNECTING = 2;

    /**
     * STATE_WAITCONACK
     */
    const STATE_WAITCONACK = 3;

    /**
     * STATE_ESTABLISHED
     */
    const STATE_ESTABLISHED = 4;

    /**
     * STATE_DISCONNECTING
     */
    const STATE_DISCONNECTING = 5;

    /**
     * STATE_DISCONNECT
     */
    const STATE_DISCONNECT = 6;

    /**
     * @var callable
     */
    public $onConnect = null;

    /**
     * @var callable
     */
    public $onReconnect = null;

    /**
     * @var callable
     */
    public $onClose = null;

    /**
     * @var callable
     */
    public $onError = null;

    /**
     * @var int
     */
    protected $_state = 1;

    /**
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * @var AsyncTcpConnection
     */
    protected $_connection = null;

    /**
     * @var boolean
     */
    protected $_firstConnect = true;

    /**
     * @var int
     */
    protected $_checkConnectionTimeoutTimer = 0;

    /**
     * @var int
     */
    protected $_pingTimer = 0;

    /**
     * @var bool
     */
    protected $_recvPingResponse = true;

    /**
     * @var bool
     */
    protected $_doNotReconnect = false;

    /**
     * @var array
     */
    protected $_subscriptions = [];


    /**
     * @var array
     */
    protected $_options = [
        'vhost'            => '/',
        'login'            => 'guest',
        'passcode'         => 'guest',
        'bindto'           => '',
        'ssl'              => [],
        'connect_timeout'  => 30,
        'reconnect_period' => 2,
        'debug'            => false,
    ];

    /**
     * Client constructor.
     * @param $address
     * @param array $options
     */
    public function __construct($address, $options = [])
    {
        class_alias('\Workerman\Stomp\Protocols\Stomp', '\Workerman\Protocols\Stomp');
        $this->setOptions($options);
        $context = [];
        if ($this->_options['bindto']) {
            $context['socket'] = ['bindto' => $this->_options['bindto']];
        }
        if ($this->_options['ssl'] && is_array($this->_options['ssl'])) {
            $context['ssl'] = $this->_options['ssl'];
        }
        
        $this->_remoteAddress = $address;
        $this->_connection    = new AsyncTcpConnection($address, $context);
        $this->onReconnect    = [$this, 'onStompReconnect'];
        $this->onMessage      = function(){};
        if ($this->_options['ssl']) {
            $this->_connection->transport = 'ssl';
        }
    }

    /**
     * connect
     */
    public function connect()
    {
        $this->_doNotReconnect           = false;
        $this->_connection->onConnect    = [$this, 'onConnectionConnect'];
        $this->_connection->onMessage    = [$this, 'onConnectionMessage'];
        $this->_connection->onError      = [$this, 'onConnectionError'];
        $this->_connection->onClose      = [$this, 'onConnectionClose'];
        $this->_connection->onBufferFull = [$this, 'onConnectionBufferFull'];
        $this->_state                    = static::STATE_CONNECTING;
        $this->_connection->connect();
        $this->setConnectionTimeout($this->_options['connect_timeout']);
        if ($this->_options['debug']) {
            echo "-> Try to connect to {$this->_remoteAddress}", PHP_EOL;
        }
    }

    /**
     * subscribe
     *
     * @param $topic
     * @param array $options
     * @param callable $callback
     */
    public function subscribe($destination, $callback, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return;
        }
        $raw_headers            = $headers;
        $headers['id']          = isset($headers['id']) ? $headers['id'] : $this->createClientId();
        $headers['ack']         = isset($headers['ack']) ? $headers['ack'] : 'auto';
        $subscription           = $headers['id'];
        $headers['destination'] = $destination;

        $package = [
            'cmd'     => 'SUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);

        $this->_subscriptions[$subscription] = [
            'ack'         => $headers['ack'],
            'callback'    => $callback,
            'headers'     => $raw_headers,
            'destination' => $destination,
        ];
        return $subscription;
    }

    public function subscribeWithAck($destination, $callback, array $headers = [])
    {
        if (!isset($headers['ack']) || $headers['ack'] === 'auto') {
            $headers['ack'] = 'client';
        }
        return $this->subscribe($destination, $callback, $headers);
    }

    /**
     * @param $subscription
     * @param array $headers
     */
    public function unsubscribe($subscription, array $headers = [])
    {
        if ($this->checkDisconnecting()) {
            return;
        }
        $default_headers = [
            'id'  => $subscription,
        ];
        $headers = array_merge($default_headers, $headers);

        $package = [
            'cmd'     => 'UNSUBSCRIBE',
            'headers' => $headers
        ];

        $this->sendPackage($package);
        unset($this->_subscriptions[$subscription]);
    }

    /**
     * @param $subscription
     * @param $message_id
     * @param array $headers
     */
    public function ack($subscription, $message_id, array $headers = [])
    {
        $headers['subscription'] = $subscription;
        $headers['message-id']   = $message_id;
        $this->sendPackage([
            'cmd'     => 'ACK',
            'headers' => $headers
        ]);
    }

    /**
     * @param $subscription
     * @param $message_id
     * @param array $headers
     */
    public function nack($subscription, $message_id, array $headers = [])
    {
        $headers['subscription'] = $subscription;
        $headers['message-id']   = $message_id;
        $this->sendPackage([
            'cmd'     => 'NACK',
            'headers' => $headers
        ]);
    }

    /**
     * @param $destination
     * @param $body
     * @param array $headers
     */
    public function send($destination, $body, array $headers = [])
    {

        $headers['destination']    = $destination;
        $headers['content-length'] = strlen($body);
        if (!isset($headers['content-type'])) {
            $headers['content-type'] = 'text/plain';
        }

        $package = [
            'cmd'     => 'SEND',
            'headers' => $headers,
            'body'    => $body
        ];

        $this->sendPackage($package);
    }

    /**
     * disconnect
     */
    public function disconnect()
    {
        $this->_state = static::STATE_DISCONNECTING;
        $this->_doNotReconnect = true;
        $this->sendPackage(['cmd' => 'DISCONNECT', ['receipt' => $this->createReceiptId()]]);
    }

    /**
     * close
     */
    public function close()
    {
        $this->_doNotReconnect = true;
        if ($this->_options['debug']) {
            echo "-> Connection->close() called", PHP_EOL;
        }
        $this->_connection->destroy();
    }

    /**
     * getState
     */
    public function getState()
    {
        return $this->_state;
    }

    /**
     * reconnect
     *
     * @param int $after
     */
    public function reconnect($after = 0)
    {
        $this->_doNotReconnect        = false;
        $this->_connection->onConnect = [$this, 'onConnectionConnect'];
        $this->_connection->onMessage = [$this, 'onConnectionMessage'];
        $this->_connection->onError   = [$this, 'onConnectionError'];
        $this->_connection->onClose   = [$this, 'onConnectionClose'];
        $this->_connection->reConnect($after);
        $this->setConnectionTimeout($this->_options['connect_timeout'] + $after);
        if ($this->_options['debug']) {
            echo "-- Reconnect after $after seconds", PHP_EOL;
        }
    }

    /**
     * onConnectionConnect
     */
    public function onConnectionConnect()
    {
        if ($this->_doNotReconnect) {
            $this->close();
            return;
        }
        $this->_state = static::STATE_WAITCONACK;
        if ($this->_options['debug']) {
            echo "-- Tcp connection established", PHP_EOL;
        }
        $headers = ['host' => $this->_options['vhost']];
        if ($this->_options['login'] !== null && $this->_options['passcode'] !== null) {
            $headers['login']    = $this->_options['login'];
            $headers['passcode'] = $this->_options['passcode'];
        }
        $this->sendPackage([
            'cmd'     => 'CONNECT',
            'headers' => $headers
        ]);
    }

    /**
     * onStompReconnect
     */
    public function onStompReconnect()
    {
        foreach ($this->_subscriptions as $subscription => $item) {
            $callback = $item['callback'];
            $destination = $item['destination'];
            $headers = $item['headers'];
            $headers['id']  = $subscription;
            $headers['ack'] = $item['ack'];
            $this->subscribe($destination, $callback, $headers);
        }
    }

    /**
     * onConnectionMessage
     *
     * @param $connection
     * @param $data
     */
    public function onConnectionMessage($connection, $data)
    {
        if ($this->_options['debug']) {
            $this->echoDebug($data);
        }
        $cmd = $data['cmd'];
        switch ($cmd) {
            case 'CONNECTED':
                $this->_state = static::STATE_ESTABLISHED;
                $this->cancelConnectionTimeout();
                if ($this->_firstConnect) {
                    if ($this->onConnect) {
                        call_user_func($this->onConnect, $this);
                    }
                    $this->_firstConnect = false;
                } else {
                    if ($this->onReconnect) {
                        call_user_func($this->onReconnect, $this);
                    }
                }
                return;
            case 'MESSAGE':
                $headers = $data['headers'];
                $message_id = $headers['message-id'];
                $subscription = $headers['subscription'];

                if (!isset($this->_subscriptions[$subscription])) {
                    return;
                }

                $callback = $this->_subscriptions[$subscription]['callback'];

                $resolver = new AckResolver($this, $subscription, $message_id);
                if ('auto' == $this->_subscriptions[$subscription]['ack']) {
                    $resolver->done();
                }
                call_user_func($callback, $this, $data, $resolver);

                return;
            case 'ERROR':
                $exception = new Exception($data['headers']['message']);
                $exception->frame = $data;
                $this->triggerError($exception);
                return;
            case 'RECEIPT':
                if ($this->_state === static::STATE_DISCONNECTING) {
                    $this->close();
                }
                return;
            default :
                echo "unknown cmd $cmd\n";
        }
    }

    /**
     * @param $package
     * @param string $type
     */
    protected function echoDebug($package, $type = 'recv')
    {
        $headers = [];
        if (isset($package['headers'])) {
            foreach ($package['headers'] as $key => $value) {
                $headers[] = "$key:$value";
            }
        }
        $type = $type == 'recv' ? '<- Recv' : '-> Send';
        $body = isset($package['body']) ? $package['body'] : '';
        echo "$type {$package['cmd']} package, header[".implode(' ', $headers)."] body[$body]" . PHP_EOL;
    }

    /**
     * onConnectionClose
     */
    public function onConnectionClose()
    {
        if ($this->_options['debug']) {
            echo "-- Connection closed", PHP_EOL;
        }
        $this->cancelPingTimer();
        $this->cancelConnectionTimeout();
        $this->_recvPingResponse = true;
        $this->_state = static::STATE_DISCONNECT;
        if (!$this->_doNotReconnect && $this->_options['reconnect_period'] > 0) {
            $this->reConnect($this->_options['reconnect_period']);
        }

        if ($this->onClose) {
            call_user_func($this->onClose, $this);
        }
    }

    /**
     * onConnectionError
     *
     * @param $connection
     * @param $code
     */
    public function onConnectionError($connection, $code)
    {
        // Connection error
        if ($code === 1) {
            $this->triggerError('Connection fail');
        // Send fail, connection closed
        } else {
            $this->triggerError('Connection closed');
        }

    }

    /**
     * onConnectionBufferFull
     */
    public function onConnectionBufferFull()
    {
        if ($this->_options['debug']) {
            echo "-- Connection buffer full and close connection", PHP_EOL;
        }
        $this->triggerError('Connection buffer full and close connection');
        $this->_connection->destroy();
    }


    /**
     * triggerError
     *
     * @param $exception
     * @param $callback
     */
    protected function triggerError($exception, $callback = null)
    {
        if (is_string($exception)) {
            $exception = new Exception($exception);
        }
        if ($this->_options['debug']) {
            echo "-- Error: ".$exception->getMessage() . PHP_EOL;
        }
        if (!$callback) {
            $callback = $this->onError ? $this->onError : function ($exception) {
                echo "Stomp client: ", $exception->getMessage(), PHP_EOL;
            };
        }
        call_user_func($callback, $exception);
    }

    /**
     * createClientId
     *
     * @return string
     */
    protected function createClientId()
    {
        static $id = 0;
        $id ++;
        return 'client-' . $id;
    }

    /**
     * @return int
     */
    public function createReceiptId()
    {
        return mt_rand();
    }

    /**
     * addCheckTimeoutTimer
     */
    protected function setConnectionTimeout($timeout)
    {
        $this->cancelConnectionTimeout();
        $this->_checkConnectionTimeoutTimer = Timer::add($timeout, [$this, 'checkConnectTimeout'], null, false);
    }

    /**
     * cancelConnectionTimeout
     */
    protected function cancelConnectionTimeout()
    {
        if ($this->_checkConnectionTimeoutTimer) {
            Timer::del($this->_checkConnectionTimeoutTimer);
            $this->_checkConnectionTimeoutTimer = 0;
        }
    }

    /**
     * setPingTimer
     */
    protected function setPingTimer($ping_interval)
    {
        $this->cancelPingTimer();
        $connection = $this->_connection;
        $this->_pingTimer = Timer::add($ping_interval, function() use ($connection) {
            if (!$this->_recvPingResponse) {
                if ($this->_options['debug']) {
                    echo "<- Recv PINGRESP timeout", PHP_EOL;
                    echo "-> Close connection", PHP_EOL;
                }
                $this->_connection->destroy();
                return;
            }
            if ($this->_options['debug']) {
                echo "-> Send PINGREQ package", PHP_EOL;
            }
            $this->_recvPingResponse = false;
            if ($this->_state === static::STATE_ESTABLISHED) {
                //$connection->send(['cmd' => 'PING']);
            }
        });
    }

    /**
     * cancelPingTimer
     */
    protected function cancelPingTimer()
    {
        if ($this->_pingTimer) {
            Timer::del($this->_pingTimer);
            $this->_pingTimer = 0;
        }
    }

    /**
     * checkConnectTimeout
     */
    public function checkConnectTimeout()
    {
        if ($this->_state === static::STATE_CONNECTING || $this->_state === static::STATE_WAITCONACK) {
            $this->triggerError('Connection timeout');
            $this->_connection->destroy();
        }
    }

    /**
     * checkDisconnecting
     *
     * @param null $callback
     * @return bool
     */
    protected function checkDisconnecting()
    {
        if (!in_array($this->_state, [
            static::STATE_ESTABLISHED,
            static::STATE_WAITCONACK,
            static::STATE_DISCONNECTING
        ])) {
            $this->triggerError('Connection not established');
            return true;
        }
        return false;
    }

    /**
     * sendPackage
     *
     * @param $package
     */
    protected function sendPackage($package)
    {
        if ($this->checkDisconnecting()) {
            return;
        }
        if ($this->_options['debug']) {
            $this->echoDebug($package, 'send');
        }
        $this->_connection->send($package);
    }

    /**
     * set options.
     *
     * @param $options
     * @throws \Exception
     */
    protected function setOptions($options)
    {
        $this->_options = array_merge($this->_options, $options);
    }
}
