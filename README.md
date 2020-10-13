# MQTT
Asynchronous MQTT client for PHP based on workerman.

# Installation
composer require workerman/mqtt

# 文档
[中文文档](http://doc.workerman.net/components/workemran-mqtt.html)

# Example
**subscribe.php**
```php
<?php
require __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
$worker = new Worker();
$worker->onWorkerStart = function(){
    $mqtt = new Workerman\Mqtt\Client('mqtt://test.mosquitto.org:1883');
    $mqtt->onConnect = function($mqtt) {
        $mqtt->subscribe('test');
    };
    $mqtt->onMessage = function($topic, $content){
        var_dump($topic, $content);
    };
    $mqtt->connect();
};
Worker::runAll();
```
Run with command ```php subscribe.php start```

**test.php**
```php
<?php
require __DIR__ . '/../vendor/autoload.php';
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Stomp\Client;

$worker = new Worker();
$worker->onWorkerStart = function(){
    $client = new Workerman\Stomp\Client('stomp://127.0.0.1:61613');
    $client->onConnect = function(Client $client) {
        $client->subscribe('/topic/foo', function(Client $client, $data) {
            var_export($data);
        });
    };
    $client->onError = function ($e) {
        echo $e;
    };
    Timer::add(1, function () use ($client) {
        $client->send('/topic/foo', 'Hello Workerman STOMP');
    });
    $client->connect();
};
Worker::runAll();
```

Run with command ```php publish.php start```


# License

MIT






