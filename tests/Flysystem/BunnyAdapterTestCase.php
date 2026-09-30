<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests\Flysystem;

use DateTimeImmutable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;

/**
 * Runs League's Flysystem adapter conformance suite against BunnyCDNAdapter.
 * Package specific tests live in AdapterTest.php (Pest). See LiveCredentials to run against a real storage zone.
 */
abstract class BunnyAdapterTestCase extends FilesystemAdapterTestCase
{
    public const string DEMO_URL = 'https://example.org.local';

    public static function isLive(): bool
    {
        return LiveCredentials::enabled();
    }

    public static function publicUrl(): string
    {
        return rtrim(LiveCredentials::publicUrl() ?? self::DEMO_URL, '/');
    }

    public static function bunnyCDNClient(): BunnyCDNClient
    {
        if ($client = LiveCredentials::client()) {
            return $client;
        }

        $mockedClient = new MockClient('test_storage_zone', '123');

        // Only used by writeBatch(), which sends requests through Guzzle directly
        $mockedClient->guzzleClient = new Guzzle([
            'handler' => function (Request $request) use ($mockedClient): Response {
                $path = $request->getUri()->getPath();
                $method = $request->getMethod();

                return match (true) {
                    $method === 'PUT' && $path === 'destination.txt' => self::store($mockedClient, 'destination.txt', 'text'),
                    $method === 'PUT' && $path === 'destination2.txt' => self::store($mockedClient, 'destination2.txt', 'text2'),
                    $method === 'PUT' && in_array($path, ['failing.txt', 'failing2.txt'], true) => throw new RuntimeException('Failed to write file'),
                    default => throw new RuntimeException("Unexpected request: $method $path"),
                };
            },
        ]);

        return $mockedClient;
    }

    private static function store(MockClient $client, string $path, string $contents): Response
    {
        $client->filesystem->write($path, $contents);

        return new Response(200);
    }

    #[\Override]
    public static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new BunnyCDNAdapter(self::bunnyCDNClient(), self::publicUrl())->setTokenAuthKey('test-token-auth-key');
    }

    /*
     * Overrides of conformance tests
     */

    #[\Override]
    public function setting_visibility(): void
    {
        $this->markTestSkipped('No visibility support is provided for BunnyCDN');
    }

    #[Test]
    #[\Override]
    public function file_exists_on_directory_is_false(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $this->assertFalse($adapter->directoryExists('test'));
            $adapter->createDirectory('test', new Config());
            $this->assertTrue($adapter->directoryExists('test'));
            $this->assertFalse($adapter->fileExists('test'));
        });
    }

    #[Test]
    #[\Override]
    public function directory_exists_on_file_is_false(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $this->assertFalse($adapter->fileExists('test.txt'));
            $adapter->write('test.txt', 'aaa', new Config());
            $this->assertTrue($adapter->fileExists('test.txt'));
            $this->assertFalse($adapter->directoryExists('test.txt'));
        });
    }

    /** The original fetches the URL, which only works against a real pull zone */
    #[Test]
    #[\Override]
    public function generating_a_public_url(): void
    {
        if (self::isLive() && ! str_starts_with(self::publicUrl(), self::DEMO_URL)) {
            parent::generating_a_public_url();

            return;
        }

        $this->assertSame(self::publicUrl().'/path.txt', $this->adapter()->publicUrl('/path.txt', new Config()));
    }

    #[Test]
    #[\Override]
    public function generating_a_temporary_url(): void
    {
        $adapter = new BunnyCDNAdapter(self::bunnyCDNClient(), '')->setTokenAuthKey('test-key');

        $url = $adapter->temporaryUrl('path.txt', new DateTimeImmutable('+1 hour'), new Config());

        $this->assertStringContainsString('path.txt?token=', $url);
        $this->assertStringContainsString('&expires=', $url);
    }

    #[Test]
    #[\Override]
    public function overwriting_a_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', ['visibility' => Visibility::PUBLIC]);
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'new contents', new Config(['visibility' => Visibility::PRIVATE]));

            $this->assertSame('new contents', $adapter->read('path.txt'));
        });
    }

    #[Test]
    #[\Override]
    public function get_checksum(): void
    {
        $adapter = $this->adapter();

        $adapter->write('path.txt', 'foobar', new Config());

        $this->assertSame('3858f62230ac3c915f300c664312c63f', $adapter->checksum('path.txt', new Config()));
        $this->assertSame(
            'c3ab8ff13720e8ad9047dd39466b3c8974e592c2fa383d4a3960714caef0c4f2',
            $adapter->checksum('path.txt', new Config(['checksum_algo' => 'sha256']))
        );
    }
}
