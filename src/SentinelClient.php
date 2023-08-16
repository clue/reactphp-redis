<?php

declare(strict_types=1);

namespace Clue\React\Redis;

use Clue\React\Redis\Io\Factory;
use Clue\React\Redis\Io\StreamingClient;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Client for receiving Sentinel master url
 */
class SentinelClient
{
    /** @var array<string> */
    private $urls;

    /** @var string */
    private $masterName;

    /** @var Factory */
    private $factory;

    /** @var StreamingClient */
    private $masterClient;

    /**
     * @param array $urls list of sentinel addresses
     * @param string $masterName sentinel master name
     * @param ?ConnectorInterface $connector
     * @param ?LoopInterface $loop
     */
    public function __construct(array $urls, string $masterName, ConnectorInterface $connector = null, LoopInterface $loop = null)
    {
        $this->urls = $urls;
        $this->masterName = $masterName;
        $this->factory = new Factory($loop ?: Loop::get(), $connector);
    }

    public function masterAddress(): PromiseInterface
    {
        $chain = reject(new \RuntimeException('Initial reject promise'));
        foreach ($this->urls as $url) {
            $chain = $chain->then(function ($masterUrl) {
                return $masterUrl;
            }, function () use ($url) {
                return $this->onError($url);
            });
        }

        return $chain;
    }

    public function masterConnection(string $masterUriPath = '', array $masterUriParams = []): PromiseInterface
    {
        if (isset($this->masterClient)) {
            return resolve($this->masterClient);
        }

        return $this
            ->masterAddress()
            ->then(function (string $masterUrl) use ($masterUriPath, $masterUriParams) {
                $query = $masterUriParams ? '?' . http_build_query($masterUriParams) : '';
                return $this->factory->createClient($masterUrl . $masterUriPath . $query);
            })
            ->then(function (StreamingClient $client) {
                $this->masterClient = $client;
                return $client->role();
            })
            ->then(function (array $role) {
                $isRealMaster = ($role[0] ?? '') === 'master';
                return $isRealMaster ? $this->masterClient : reject(new \RuntimeException("Invalid master role: {$role[0]}"));
            });
    }

    private function onError(string $nextUrl): PromiseInterface
    {
        return $this->factory
            ->createClient($nextUrl)
            ->then(function (StreamingClient $client) {
                return $client->sentinel('get-master-addr-by-name', $this->masterName);
            })
            ->then(function (array $response) {
                return $response[0] . ':' . $response[1]; // ip:port
            });
    }
}
