<?php

namespace Siberfx\BunnyCdn\Tests\Flysystem;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\Test;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;

/**
 * Runs the Flysystem adapter conformance suite against the BunnyCDN adapter.
 * Package specific tests live in AdapterTest.php (Pest).
 *
 * Set $storage_zone / $api_key / $region / $public_url in tests/Flysystem/ClientDI.php to run against a live zone.
 */
abstract class BunnyAdapterTestCase extends FilesystemAdapterTestCase
{
    public const DEMOURL = 'https://example.org.local';

    protected static bool $isLive = false;

    protected static string $publicUrl = self::DEMOURL;

    public static function setUpBeforeClass(): void
    {
        LiveCredentials::load();
        global $public_url;
        if (isset($public_url)) {
            static::$publicUrl = $public_url;
        }

        static::$publicUrl = rtrim(static::$publicUrl, '/');
    }

    public static function bunnyCDNClient(): BunnyCDNClient
    {
        LiveCredentials::load();
        global $storage_zone;
        global $api_key;
        global $region;

        if ($storage_zone !== null && $api_key !== null) {
            static::$isLive = true;

            return new BunnyCDNClient($storage_zone, $api_key, $region ?? BunnyCDNRegion::DEFAULT);
        }

        $mockedClient = new MockClient('test_storage_zone', '123');

        $mockedClient->guzzleClient = new Guzzle([
            'handler' => function (Request $request) use ($mockedClient) {
                $path = $request->getUri()->getPath();
                $method = $request->getMethod();

                if ($method === 'PUT' && $path === 'destination.txt') {
                    $mockedClient->filesystem->write('destination.txt', 'text');

                    return new Response(200);
                }

                if ($method === 'PUT' && $path === 'destination2.txt') {
                    $mockedClient->filesystem->write('destination2.txt', 'text2');

                    return new Response(200);
                }

                if ($method === 'PUT' && \in_array($path, ['failing.txt', 'failing2.txt'])) {
                    throw new \RuntimeException('Failed to write file');
                }

                throw new \RuntimeException('Unexpected request: '.$method.' '.$path);
            },
        ]);

        return $mockedClient;
    }

    public static function createFilesystemAdapter(): FilesystemAdapter
    {
        $adapter = new BunnyCDNAdapter(self::bunnyCDNClient(), static::$publicUrl);
        $adapter->setTokenAuthKey('test-token-auth-key');

        return $adapter;
    }

    public static function isLive(): bool
    {
        return static::$isLive;
    }

    public static function publicUrl(): string
    {
        return static::$publicUrl;
    }

    /*
     * Overrides of conformance tests
     */

    public function setting_visibility(): void
    {
        $this->markTestSkipped('No visibility support is provided for BunnyCDN');
    }

    #[Test]
    public function file_exists_on_directory_is_false(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $this->assertFalse($adapter->directoryExists('test'));
            $adapter->createDirectory('test', new Config);
            $this->assertTrue($adapter->directoryExists('test'));
            $this->assertFalse($adapter->fileExists('test'));
        });
    }

    #[Test]
    public function directory_exists_on_file_is_false(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();

            $this->assertFalse($adapter->fileExists('test.txt'));
            $adapter->write('test.txt', 'aaa', new Config);
            $this->assertTrue($adapter->fileExists('test.txt'));
            $this->assertFalse($adapter->directoryExists('test.txt'));
        });
    }

    /**
     * The original tries to access the URL
     */
    #[Test]
    public function generating_a_public_url(): void
    {
        if (self::$isLive && ! \str_starts_with(static::$publicUrl, self::DEMOURL)) {
            parent::generating_a_public_url();

            return;
        }

        $url = $this->adapter()->publicUrl('/path.txt', new Config);

        self::assertEquals(static::$publicUrl.'/path.txt', $url);
    }

    #[Test]
    public function generating_a_temporary_url(): void
    {
        $adapter = new BunnyCDNAdapter(self::bunnyCDNClient(), '');
        $adapter->setTokenAuthKey('test-key');

        $url = $adapter->temporaryUrl('path.txt', new \DateTimeImmutable('+1 hour'), new Config);

        $this->assertStringContainsString('path.txt?token=', $url);
        $this->assertStringContainsString('&expires=', $url);
    }

    #[Test]
    public function overwriting_a_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', ['visibility' => Visibility::PUBLIC]);
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'new contents', new Config(['visibility' => Visibility::PRIVATE]));

            $this->assertEquals('new contents', $adapter->read('path.txt'));
        });
    }

    #[Test]
    public function get_checksum(): void
    {
        $adapter = $this->adapter();

        $adapter->write('path.txt', 'foobar', new Config);

        $this->assertSame('3858f62230ac3c915f300c664312c63f', $adapter->checksum('path.txt', new Config));
        $this->assertSame(
            'c3ab8ff13720e8ad9047dd39466b3c8974e592c2fa383d4a3960714caef0c4f2',
            $adapter->checksum('path.txt', new Config(['checksum_algo' => 'sha256']))
        );
    }
}
