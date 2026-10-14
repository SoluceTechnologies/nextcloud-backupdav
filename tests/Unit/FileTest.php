<?php

declare(strict_types=1);

namespace OCA\BackupDAV\Tests\Unit;

use OCA\BackupDAV\Dav\File;
use OCP\Files\File as NcFile;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;

class FileTest extends TestCase
{
    public function testGetReturnsTheStorageStreamNotItsContent(): void
    {
        $stream = fopen('php://memory', 'r+');
        $nc = $this->createStub(NcFile::class);
        $nc->method('fopen')->willReturn($stream);

        $this->assertSame($stream, (new File($nc))->get());
    }

    public function testGetMapsMissingFileToNotFound(): void
    {
        $nc = $this->createStub(NcFile::class);
        $nc->method('fopen')->willThrowException(new NotFoundException());

        $this->expectException(NotFound::class);
        (new File($nc))->get();
    }

    #[DataProvider('storageFailures')]
    public function testGetMapsStorageFailuresTo503(\Exception|false $failure): void
    {
        $nc = $this->createStub(NcFile::class);
        if ($failure instanceof \Exception) {
            $nc->method('fopen')->willThrowException($failure);
        } else {
            $nc->method('fopen')->willReturn($failure);
        }

        $this->expectException(ServiceUnavailable::class);
        (new File($nc))->get();
    }

    public static function storageFailures(): array
    {
        return [
            'locked by a writer' => [new LockedException('/alice/files/a.txt')],
            'S3 error' => [new \RuntimeException('S3 read timeout')],
            'fopen returned false' => [false],
        ];
    }

    public function testMetadataComesFromTheFileCache(): void
    {
        $nc = $this->createStub(NcFile::class);
        $nc->method('getName')->willReturn('contrat.pdf');
        $nc->method('getSize')->willReturn(1234);
        $nc->method('getMTime')->willReturn(1760000000);
        $nc->method('getEtag')->willReturn('abc123');
        $nc->method('getMimetype')->willReturn('application/pdf');

        $file = new File($nc);

        $this->assertSame('contrat.pdf', $file->getName());
        $this->assertSame(1234, $file->getSize());
        $this->assertSame(1760000000, $file->getLastModified());
        $this->assertSame('"abc123"', $file->getETag());
        $this->assertSame('application/pdf', $file->getContentType());
    }

    public function testWritesAreForbidden(): void
    {
        $file = new File($this->createStub(NcFile::class));
        $writes = [
            'put' => fn () => $file->put('data'),
            'delete' => fn () => $file->delete(),
            'setName' => fn () => $file->setName('other.txt'),
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
