<?php

declare(strict_types=1);

namespace Clue\Tests\React\Redis;

use Clue\React\Redis\Io\StreamingClient;
use Clue\React\Redis\SentinelClient;
use React\EventLoop\StreamSelectLoop;
use function Clue\React\Block\await;

class SentinelClientTest extends TestCase
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

    public function testMasterAddress()
    {
        $redis = new SentinelClient($this->uris, $this->masterName, null, $this->loop);
        $masterAddressPromise = $redis->masterAddress();
        $masterAddress = await($masterAddressPromise, $this->loop);
        $this->assertEquals(str_replace('localhost', '127.0.0.1', $this->masterUri), $masterAddress);
    }

    public function testMasterConnectionWithParams()
    {
        $redis = new SentinelClient($this->uris, $this->masterName, null, $this->loop);
        $masterConnectionPromise = $redis->masterConnection('/1', ['timeout' => 0.5]);
        $masterConnection = await($masterConnectionPromise, $this->loop);
        $this->assertInstanceOf(StreamingClient::class, $masterConnection);

        $pong = await($masterConnection->ping(), $this->loop);
        $this->assertEquals('PONG', $pong);
    }

    public function testConnectionFail()
    {
        $redis = new SentinelClient(['128.128.0.1:26379?timeout=0.1'], $this->masterName, null, $this->loop);
        $masterConnectionPromise = $redis->masterConnection();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection to redis://128.128.0.1:26379?timeout=0.1 timed out after 0.1 seconds');
        await($masterConnectionPromise, $this->loop);
    }

    public function testConnectionSkipInvalid()
    {
        $redis = new SentinelClient(array_merge(['128.128.0.1:26379?timeout=0.1'], $this->uris), $this->masterName, null, $this->loop);
        $masterConnectionPromise = $redis->masterConnection('/1', ['timeout' => 5]);
        $masterConnection = await($masterConnectionPromise, $this->loop);
        $this->assertInstanceOf(StreamingClient::class, $masterConnection);
    }
}
