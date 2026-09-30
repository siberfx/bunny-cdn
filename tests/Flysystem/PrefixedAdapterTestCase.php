<?php

namespace Siberfx\BunnyCdn\Tests\Flysystem;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\Test;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;

/**
 * Conformance suite for BunnyCDNAdapter wrapped in League's PathPrefixedAdapter.
 * Package specific tests live in PrefixTest.php (Pest).
 */
abstract class PrefixedAdapterTestCase extends FilesystemAdapterTestCase
{
    public const PREFIX_PATH = 'path_prefix_12345';

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

    public static function bunnyCDNAdapter(?BunnyCDNClient $client = null, string $root = ''): BunnyCDNAdapter
    {
        $adapter = new BunnyCDNAdapter($client ?? self::bunnyCDNClient(), self::PULL_ZONE, $root);
        $adapter->setTokenAuthKey('test-token-auth-key');

        return $adapter;
    }

    public static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new PathPrefixedAdapter(self::bunnyCDNAdapter(), self::PREFIX_PATH);
    }

    protected function tearDown(): void
    {
        try {
            (new Filesystem(self::bunnyCDNAdapter()))->deleteDirectory('/'.self::PREFIX_PATH);
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

        self::assertEquals(self::PULL_ZONE.self::PREFIX_PATH.'/path.txt', $url);
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

    #[Test]
    public function generating_a_temporary_url(): void
    {
        $prefixAdapter = new PathPrefixedAdapter(self::bunnyCDNAdapter(), self::PREFIX_PATH);

        $url = $prefixAdapter->temporaryUrl('path.txt', new \DateTimeImmutable('+1 hour'), new Config);

        $this->assertStringContainsString(self::PREFIX_PATH.'/path.txt?token=', $url);
        $this->assertStringContainsString('&expires=', $url);
    }

    #[Test]
    public function get_checksum(): void
    {
        $adapter = $this->adapter();

        if (! $adapter instanceof ChecksumProvider) {
            $this->markTestSkipped('Adapter does not supply providing checksums');
        }

        $adapter->write('path.txt', 'foobar', new Config);

        $this->assertSame('3858f62230ac3c915f300c664312c63f', $adapter->checksum('path.txt', new Config));
        $this->assertSame(
            'c3ab8ff13720e8ad9047dd39466b3c8974e592c2fa383d4a3960714caef0c4f2',
            $adapter->checksum('path.txt', new Config(['checksum_algo' => 'sha256']))
        );
    }
}
