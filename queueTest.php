<?php

use Aws\Sqs\SqsClient;
use Bernard\Driver\SqsDriver;
use Dotenv\Dotenv;

require 'vendor/autoload.php';
$dotenv = new Dotenv(__DIR__);
$dotenv->load();

function get_driver()
{
    $connection = SqsClient::factory([
        'key' => getenv('AWS_KEY'),
        'secret' => getenv('AWS_SECRET'),
        'region' => getenv('AWS_REGION')
    ]);

    $driver = new SqsDriver($connection, [
        'echo-time' => getenv('SQS_QUEUE_URL'),
    ]);

    return $driver;
}

require 'vendor/bernard/bernard/example/bootstrap.php';

