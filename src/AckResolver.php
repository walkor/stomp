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

use Workerman\Stomp\Client;

class AckResolver
{
    /**
     * @var Client
     */
    protected $_client;
    protected $_subscription;
    protected $_messageId;
    protected $_done;

    public function __construct(Client $client, $subscription, $messageId)
    {
        $this->_client = $client;
        $this->_subscription = $subscription;
        $this->_messageId = $messageId;
    }

    public function ack(array $headers = array())
    {
        if ($this->_done) {
            return;
        }
        $this->_client->ack($this->_subscription, $this->_messageId, $headers);
        $this->_done = true;
        $this->_client = null;
    }

    public function nack(array $headers = array())
    {
        if ($this->_done) {
            return;
        }
        $this->_client->nack($this->_subscription, $this->_messageId, $headers);
        $this->_done = 1;
        $this->_client = null;
    }

    public function done()
    {
        $this->_done = true;
    }

}
