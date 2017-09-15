<?php

use Aws\Sqs\SqsClient;
use Bernard\Consumer;
use Bernard\Driver\PredisDriver;
use Bernard\Driver\SqsDriver;
use Bernard\EventListener\ErrorLogSubscriber;
use Bernard\EventListener\FailureSubscriber;
use Bernard\Normalizer\DefaultMessageNormalizer;
use Bernard\Normalizer\EnvelopeNormalizer;
use Bernard\Producer;
use Bernard\QueueFactory\PersistentFactory;
use Bernard\Router\SimpleRouter;
use Bernard\Serializer;
use Dotenv\Dotenv;
use League\Tactician\Bernard\QueueableCommand;
use League\Tactician\Bernard\QueueMiddleware;
use League\Tactician\Bernard\Receiver\SameBusReceiver;
use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\CommandNameExtractor\ClassNameExtractor;
use League\Tactician\Handler\Locator\InMemoryLocator;
use League\Tactician\Handler\MethodNameInflector\HandleInflector;
use Normalt\Normalizer\AggregateNormalizer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

require 'vendor/autoload.php';
$dotenv = new Dotenv(__DIR__);
$dotenv->load();

class RentMovieHandler
{
    public function handle(RentMovieCommand $command)
    {
        echo $command->getTitle() . " is available to rent.\n";
    }
}

class BuyMovieHandler
{
    public function handle(BuyMovieCommand $command)
    {
        echo 'You bought ' . $command->getTitle() . "\n";
    }
}

class RentMovieCommand implements QueueableCommand
{
    private $title;

    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getName()
    {
        // this needs to be the StudlyCase version of the queue name
        // and needs to be listed in the routes given to the queue router
        return 'RentMovie';
    }

}

class BuyMovieCommand
{
    private $title;

    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    public function getTitle()
    {
        return $this->title;
    }

}

// ------ Tactician Command Bus Stuff ------

class LazyLoadingLocator extends InMemoryLocator
{
    private $commandClasses;

    /**
     * Associate a handler class name with a command class name. If $handler is callable, pass through to parent.
     *
     * @param string|object $handler Handler to receive class
     * @param string $commandClassName Command class e.g. "My\TaskAddedCommand"
     */
    public function addHandler($handler, $commandClassName)
    {
        if (is_callable($handler)) {
            parent::addHandler($handler, $commandClassName);
        }
        $this->commandClasses[$commandClassName] = $handler;
    }

    /**
     * If the requested command is handled by one of the classes in our command array, instantiate that class
     * and return it. If not found, pass through to parent.
     *
     * @param string $commandName
     * @return object
     */
    public function getHandlerForCommand($commandName)
    {
        if (isset($this->commandClasses[$commandName])) {
            // todo: invoke the DI container to make the command handler
            return new $this->commandClasses[$commandName];
        }
        return parent::getHandlerForCommand($commandName);
    }
}

function getCommandBus()
{
    return new CommandBus(getMiddleware());
}

function getMiddleware()
{
    return [
        new QueueMiddleware(getQueueProducer()), // this is the link between Tactician and Bernard
        new CommandHandlerMiddleware(getNameExtractor(), getLocator(), getInflector())
    ];
}

function getNameExtractor()
{
    return new ClassNameExtractor(); // command name matches command class name
}

function getLocator()
{
    // this array will contain all of our handler => command pairs
    // if using the InMemoryLocator, you must instantiate each of the handlers here
    $commandPairs = [
        RentMovieHandler::class => RentMovieCommand::class,
        BuyMovieHandler::class => BuyMovieCommand::class,
    ];

    $locator = new LazyLoadingLocator();

    foreach ($commandPairs as $handler => $command) {
        $locator->addHandler($handler, $command);
    }

    return $locator;
}

function getInflector()
{
    return new HandleInflector(); // call the handle() method of the command class
}

// ----- Bernard Queue Management Stuff -----

function getQueueProducer()
{
    return new Producer(getQueueFactory(), getEventDispatcher());
}

function getEventDispatcher()
{
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new ErrorLogSubscriber);
    $dispatcher->addSubscriber(new FailureSubscriber(getQueueFactory()));

    return $dispatcher;
}

function getQueueFactory()
{
    return new PersistentFactory(getSqsDriver(), getQueueSerializer());
}

function getQueueSerializer()
{
    $aggregateNormalizer = new AggregateNormalizer([
        new EnvelopeNormalizer(),
        new GetSetMethodNormalizer(),
        new DefaultMessageNormalizer(),
    ]);

    return new Serializer($aggregateNormalizer);
}

function getPredisDriver()
{
    return new PredisDriver(new Client(null, ['prefix' => 'bernard:']));
}

function getSqsDriver()
{
    $connection = SqsClient::factory([
        'key' => getenv('AWS_KEY'),
        'secret' => getenv('AWS_SECRET'),
        'region' => getenv('AWS_REGION')
    ]);

    // with aliased queue urls
    $driver = new SqsDriver($connection, getQueueList());

    return $driver;
}

function getQueueList()
{
    return [
        'rent-movie' => getenv('SQS_QUEUE_URL'),
    ];
}

function getQueuedRoutes()
{
    return [
        'RentMovie'
    ];
}

function getQueueConsumer()
{
    return new Consumer(getQueueRouter(), new EventDispatcher());
}

function getQueueRouter()
{
    $router = new SimpleRouter();

    foreach (getQueuedRoutes() as $routeName) {
        $router->add($routeName, getQueueReceiver());
    }

    return $router;
}

function getQueueReceiver()
{
    return new SameBusReceiver(getCommandBus());
}

function workQueues()
{
    $queueFactory = getQueueFactory();
    $consumer = getQueueConsumer();

    foreach (getQueueList() as $queueName => $queueUrl) {
        echo "checking queue $queueName\n";
        $queue = $queueFactory->create($queueName);
        $consumer->consume($queue, ['stop-when-empty' => true]);
    }
}

function postCommand($action, $title)
{
    $commandBus = getCommandBus();

    switch ($action) {
        case 'rent':
            $command = new RentMovieCommand();
            break;
        case 'buy':
            $command = new BuyMovieCommand();
            break;
    }

    $command->setTitle($title);
    $commandBus->handle($command);
}

$action = $argv[1];
$title = $argv[2];

try {
    workQueues();
    postCommand($action, $title);

} catch (\Exception $e) {
    echo get_class($e) . "\n" . $e->getMessage() . "\nat " . $e->getFile() . ', line ' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
}