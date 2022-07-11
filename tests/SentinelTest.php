<?php

declare(strict_types=1);

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Io\StreamingClient;
use Clue\React\Redis\SentinelClient;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use function Clue\React\Block\await;

class SentinelTest extends TestCase
{
    /** @var StreamSelectLoop */
    private $loop;

    /** @var string */
    private $masterUri;

    /** @var array */
    private $uris;

    /** @var string */
    private $masterName;

    public function setUp(): void
    {
        $this->masterUri = getenv('REDIS_URI') ?: '';
        if ($this->masterUri === '') {
            $this->markTestSkipped('No REDIS_URI environment variable given for Sentinel tests');
        }

        $uris = getenv('REDIS_URIS') ?: '';
        if ($uris === '') {
            $this->markTestSkipped('No REDIS_URIS environment variable given for Sentinel tests');
        }
        $this->uris = array_map('trim', explode(',', $uris));

        $this->masterName = getenv('REDIS_SENTINEL_MASTER') ?: '';
        if ($this->masterName === '') {
            $this->markTestSkipped('No REDIS_SENTINEL_MASTER environment variable given for Sentinel tests');
        }

        $this->loop = new StreamSelectLoop();
    }

    public function testMasterUrl()
    {
        $redis = new SentinelClient($this->uris, $this->masterName, null, $this->loop);
        $masterUrlPromise = $redis->masterUrl();
        $this->assertInstanceOf(PromiseInterface::class, $masterUrlPromise);

        $masterUrl = await($masterUrlPromise, $this->loop);

        $this->assertEquals($this->masterUri, $masterUrl);
    }

    public function testMasterConnection()
    {
        $redis = new SentinelClient($this->uris, $this->masterName, null, $this->loop);
        $masterConnectionPromise = $redis->masterConnection();
        $this->assertInstanceOf(PromiseInterface::class, $masterConnectionPromise);

        $masterConnection = await($masterConnectionPromise, $this->loop);

        $this->assertInstanceOf(StreamingClient::class, $masterConnection);
    }
}
