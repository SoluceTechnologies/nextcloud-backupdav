<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\StorageFile;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\ServiceUnavailable;

class StorageFileTest extends TestCase
{
    use MocksNodes;

    public function testGetStreamsTheFileFromTheStorage(): void
    {
        $stream = fopen('php://memory', 'r+');
        $storage = $this->createMock(IStorage::class);
        $storage->expects($this->once())->method('fopen')->with('sub/secret.txt', 'r')->willReturn($stream);

        $this->assertSame($stream, (new StorageFile($storage, $this->cacheEntry('sub/secret.txt')))->get());
    }

    #[DataProvider('storageFailures')]
    public function testGetMapsStorageFailuresTo503(\Exception|false $failure): void
    {
        $storage = $this->createStub(IStorage::class);
        if ($failure instanceof \Exception) {
            $storage->method('fopen')->willThrowException($failure);
        } else {
            $storage->method('fopen')->willReturn($failure);
        }

        $this->expectException(ServiceUnavailable::class);
        (new StorageFile($storage, $this->cacheEntry('a.txt')))->get();
    }

    public static function storageFailures(): array
    {
        return [
            'S3 error' => [new \RuntimeException('S3 read timeout')],
            'fopen returned false' => [false],
        ];
    }

    public function testMetadataComesFromTheFileCache(): void
    {
        $file = new StorageFile($this->createStub(IStorage::class), $this->cacheEntry('sub/bilan.xlsx', 'application/vnd.ms-excel', 1234));

        $this->assertSame('bilan.xlsx', $file->getName());
        $this->assertSame(1234, $file->getSize());
        $this->assertSame(1760000000, $file->getLastModified());
        $this->assertSame('"etag-sub/bilan.xlsx"', $file->getETag());
        $this->assertSame('application/vnd.ms-excel', $file->getContentType());
    }

    public function testWritesAreForbidden(): void
    {
        $file = new StorageFile($this->createStub(IStorage::class), $this->cacheEntry('a.txt'));
        $writes = [
            'put' => fn () => $file->put('data'),
            'delete' => fn () => $file->delete(),
            'setName' => fn () => $file->setName('b.txt'),
        ];
        foreach ($writes as $name => $write) {
            try {
                $write();
                $this->fail("$name was accepted");
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
