<?php

namespace Zwartpet\PHPCertificateToolbox;

use Zwartpet\PHPCertificateToolbox\Exception\RuntimeException;

/**
 * Tests FilesystemCertificateStorage
 *
 * @package Zwartpet\PHPCertificateToolbox
 */
class FilesystemAccountStorageTest extends LETestCase
{
    public function testBadDir()
    {
        $this->expectException(RuntimeException::class);
        $dir=sys_get_temp_dir().'/this/does/not/exist';
        new FilesystemCertificateStorage($dir);
    }

    public function testStore()
    {
        $dir=sys_get_temp_dir().'/store-test';
        $this->deleteDirectory($dir);
        $store = new FilesystemAccountStorage($dir);

        $this->assertNull($store->getAccountPrivateKey());
        $store->setAccountPrivateKey('abcd1234');
        $this->assertEquals('abcd1234', $store->getAccountPrivateKey());

        $this->assertNull($store->getAccountPublicKey());
        $store->setAccountPublicKey('efgh2345');
        $this->assertEquals('efgh2345', $store->getAccountPublicKey());
    }
}
