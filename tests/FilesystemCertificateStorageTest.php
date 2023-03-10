<?php

namespace Zwartpet\PHPCertificateToolbox;

use Zwartpet\PHPCertificateToolbox\Exception\RuntimeException;

/**
 * Tests FilesystemCertificateStorage
 *
 * @package Zwartpet\PHPCertificateToolbox
 */
class FilesystemCertificateStorageTest extends LETestCase
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
        $store = new FilesystemCertificateStorage($dir);

        $domain='*.example.org';
        $this->assertNull($store->getCertificate($domain));
        $store->setCertificate($domain, 'ijkl3456');
        $this->assertEquals('ijkl3456', $store->getCertificate($domain));

        $this->assertNull($store->getFullChainCertificate($domain));
        $store->setFullChainCertificate($domain, 'mnop4567');
        $this->assertEquals('mnop4567', $store->getFullChainCertificate($domain));

        $this->assertNull($store->getPrivateKey($domain));
        $store->setPrivateKey($domain, 'qrst5678');
        $this->assertEquals('qrst5678', $store->getPrivateKey($domain));


        $key='banjo';
        $this->assertFalse($store->hasMetadata($key));
        $this->assertNull($store->getMetadata($key));
        $store->setMetadata($key, 'uvwx6789');
        $this->assertTrue($store->hasMetadata($key));
        $this->assertEquals('uvwx6789', $store->getMetadata($key));
    }
}
