# STOMP
Asynchronous STOMP client for PHP based on [workerman](https://github.com/walkor/workerman).

# Installation
```
composer require workerman/stomp
```

# Example
**test.php**
```php
<?php
require __DIR__ . '/../vendor/autoload.php';
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Stomp\Client;

$worker = new Worker();
$worker->onWorkerStart = function(){
    $client = new Workerman\Stomp\Client('stomp://127.0.0.1:61613', array(
        'debug' => true,
    ));
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
Run with command ```php test.php start```


# License

MIT






