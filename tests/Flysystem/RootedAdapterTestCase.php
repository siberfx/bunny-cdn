<?php

namespace Siberfx\BunnyCdn\Tests\Flysystem;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\Test;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;

/**
 * Conformance suite for BunnyCDNAdapter scoped to a root path.
 * Package specific tests live in RootTest.php (Pest).
 */
abstract class RootedAdapterTestCase extends FilesystemAdapterTestCase
{
    public const ROOT_PATH = 'root_prefix_12345';

    public const PULL_ZONE = 'https://example.org.local/assets/';

    public static function bunnyCDNClient(): BunnyCDNClient
    {
        LiveCredentials::load();
        global $storage_zone;
        global $api_key;

        if ($storage_zone !== null && $api_key !== null) {
            return new BunnyCDNClient($storage_zone, $api_key);
        }

        return new MockClient('test_storage_zone', '123');
    }

    public static function bunnyCDNAdapter(?BunnyCDNClient $client = null, string $root = self::ROOT_PATH): BunnyCDNAdapter
    {
        $adapter = new BunnyCDNAdapter($client ?? self::bunnyCDNClient(), self::PULL_ZONE, $root);
        $adapter->setTokenAuthKey('test-token-auth-key');

        return $adapter;
    }

    public static function createFilesystemAdapter(): FilesystemAdapter
    {
        return self::bunnyCDNAdapter();
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem(self::bunnyCDNAdapter()))->deleteDirectory('');
        } catch (FilesystemException) {
        }
    }

    /*
     * Overrides of conformance tests
     */

    public function setting_visibility(): void
    {
        $this->markTestSkipped('No visibility support is provided for BunnyCDN');
    }

    /**
     * The original tries to access the URL
     */
    #[Test]
    public function generating_a_public_url(): void
    {
        $url = $this->adapter()->publicUrl('path.txt', new Config);

        self::assertEquals(self::PULL_ZONE.self::ROOT_PATH.'/path.txt', $url);
    }

    public function overwriting_a_file(): void
    {
        $this->runScenario(function () {
            $this->givenWeHaveAnExistingFile('path.txt', 'contents', ['visibility' => Visibility::PUBLIC]);
            $adapter = $this->adapter();

            $adapter->write('path.txt', 'new contents', new Config(['visibility' => Visibility::PRIVATE]));

            $this->assertEquals('new contents', $adapter->read('path.txt'));
        });
    }

    /**
     * Temporary URLs must be signed against the root-scoped path.
     */
    #[Test]
    public function generating_a_temporary_url(): void
    {
        $url = self::bunnyCDNAdapter()->temporaryUrl('path.txt', new \DateTimeImmutable('+1 hour'), new Config);

        $this->assertStringContainsString(self::ROOT_PATH.'/path.txt?token=', $url);
        $this->assertStringContainsString('&expires=', $url);
    }
}
