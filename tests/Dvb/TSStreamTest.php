<?php

class TSStreamTest extends \PHPUnit\Framework\TestCase {

    public function tearDown()
    {
        \Mockery::close();
    }

    public function testAddClient() {
        $process = new \React\ChildProcess\Process('foo');
        $logger = new \Psr\Log\NullLogger();
        $loop = \React\EventLoop\Factory::create();
        $tsstream = new \PhpBg\WatchTv\Dvb\TSStream($process, $logger, $loop);

        $client = \Mockery::spy(\React\Stream\WritableStreamInterface::class);

        $this->assertEmpty($tsstream->getClients());
        $tsstream->addClient($client, [1,2,3]);
        $this->assertSame(1, count($tsstream->getClients()));
    }

    public function testRemoveClient() {
        $process = new \React\ChildProcess\Process('foo');
        $logger = new \Psr\Log\NullLogger();
        $loop = \React\EventLoop\Factory::create();
        $tsstream = new \PhpBg\WatchTv\Dvb\TSStream($process, $logger, $loop);

        $client = \Mockery::spy(\React\Stream\WritableStreamInterface::class);

        $tsstream->addClient($client, [1,2,3]);
        $this->assertSame(1, count($tsstream->getClients()));
        $tsstream->removeClient($client);
        $this->assertEmpty($tsstream->getClients());
    }

    public function testExit() {
        $process = new \React\ChildProcess\Process('foo');
        $logger = new \Psr\Log\NullLogger();
        $loop = \React\EventLoop\Factory::create();
        $tsstream = new \PhpBg\WatchTv\Dvb\TSStream($process, $logger, $loop);
        $hasExited = false;
        $tsstream->on('exit', function () use (&$hasExited, $loop) {
            $hasExited = true;
            $loop->stop();
        });
        $loop->run();
        $this->assertTrue($hasExited);
    }
}