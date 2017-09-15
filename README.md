# Tactician - Bernard example

Simple (?) example of using the Tactician command bus package from PHP League
together with the Bernard queue interface. The goal of this little project 
is to understand how all the components fit together and how classes and
queues need to be named and passed to the packages. The examples use Amazon SQS
but theoretically could use any queue supported by Bernard.

## Configuration

The examples use Vlucas/PhpDotEnv to handle configuration values. Copy or rename
`.env.example` to `.env` and edit the values.

Note that I have limited this to PHP 5.5 (although PHP 7.2 is released). 
There may be minor changes required to take advantage of more recent versions 
of PHP. I recommend you get the example working first, then update the PHP 
requirement in `composer.json` and see what breaks.

Also note that `bernard/bernard` is still at Alpha 5 as of this writing. The
`composer.json` therefore requires version `^1.0@dev`.

## queueTest

The queueTest program basically duplicates the Bernard example for SQS. In one
terminal window, run the "consume" command. In another terminal window, run
the "produce" command. The producer will start putting messages on the queue
and the consumer will take them off.

At present, nothing is displayed to confirm a message was received by the
consumer. This is because the EchoTimeService in Bernard is ignoring the
message content and, instead, generating a random number each time it is invoked.
If that number is 7, the EchoTimeService will throw an exception. So, after 
a brief pause, you should see the consumer window display an error. This is 
your indication that at least one message was placed on the queue by the 
producer and removed from the queue by the consumer.

This program is included so you can sort out your queue connection first
before trying to run the command bus.

```
php queueTest.php consume
php queueTest.php produce <--do this in a separate terminal window
```

## commandBusTest

The commandBusTest is a little more interesting. It puts together the basic
Tactician command bus components with a basic Bernard queue. The only fancy thing here is
a lazy loading Locator class. That class is totally not necessary and could be
replaced with the standard Tactician InMemoryLocator.

The commamnd bus is configured with two commands, one to rent a movie and one
to buy a movie. The buy command is executed immediately by the command bus.
The rent command is queued. Each time you run the test script, it will check
the queue and tell you that previous rental requests are available.

First we'll make a rental request:
```
php commandBusTest.php rent 'Spiderman 3'
```

The command bus builds a RentMovieCommand but, since this class implements the
QueueableCommand interface, the request for "Spiderman 3" will be placed on
the SQS queue.

Now we'll buy a movie (and pick something worth owning):
```
php commandBusTest.php buy 'Avengers'
```

The script checks the queue, finds a RentMovieCommand for "Spiderman 3" and
executes the RentMovieHandler. Then the command bus executes the BuyMovieCommand
using the BuyMovieHandler.

## Todo

Obviously, this example is not structured for immediate implementation. You'll
want to package things into appropriate service classes instead of having one
big script with a bunch of functions. This example is a demo for my local PHP 
user group but does give you a place to work things out.
