<?php declare(strict_types=1);
namespace Imbo\Storage;

use DateTime;
use DateTimeZone;
use Imbo\Exception\StorageException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\GridFS\Bucket;
use MongoDB\GridFS\Exception\FileNotFoundException;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(GridFS::class)]
class GridFSTest extends TestCase
{
    private string $user    = 'user';
    private string $imageId = 'image-id';

    public function testThrowsExceptionWhenInvalidUriIsSpecified(): void
    {
        $this->expectExceptionObject(new StorageException('Unable to connect to the database', 500));
        new GridFS('some-database', 'foo');
    }

    public function testCanStoreImageThatDoesNotAlreadyExist(): void
    {
        $bucketOptions = ['some' => 'option'];

        /** @var Bucket&MockObject */
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('findOne')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
            ])
            ->willReturn(null);

        $bucket
            ->expects($this->once())
            ->method('uploadFromStream')
            ->with(
                'user.image-id',
                $this->isResource(),
                $this->callback(function (array $data): bool {
                    return
                        $this->user === ($data['metadata']['user'] ?? null) &&
                        $this->imageId === ($data['metadata']['imageIdentifier'] ?? null) &&
                        is_int($data['metadata']['updated'] ?? null);
                }),
            );

        /** @var Database&MockObject */
        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('selectGridFSBucket')
            ->with($bucketOptions)
            ->willReturn($bucket);

        /** @var Client&MockObject */
        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('selectDatabase')
            ->with('database-name')
            ->willReturn($database);

        $adapter = new GridFS('database-name', 'uri', [], [], $bucketOptions, $client);

        $this->assertTrue(
            $adapter->store($this->user, $this->imageId, 'image data'),
            'Expected adapter to store image',
        );
    }

    public function testCanStoreImageThatAlreadyExist(): void
    {
        $bucketOptions = ['some' => 'option'];

        /** @var Bucket&MockObject */
        $bucket = $this->createConfiguredMock(Bucket::class, [
            'getBucketName' => 'fs',
        ]);
        $bucket
            ->expects($this->once())
            ->method('findOne')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
            ])
            ->willReturn($this->createMock(BSONDocument::class));

        /** @var Collection&MockObject */
        $collection = $this->createMock(Collection::class);
        $collection
            ->expects($this->once())
            ->method('updateOne')
            ->with(
                [
                    'metadata.user' => $this->user,
                    'metadata.imageIdentifier' => $this->imageId,
                ],
                $this->callback(
                    fn (array $data): bool => is_int($data['$set']['metadata.updated'] ?? null),
                ),
            );

        /** @var Database&MockObject */
        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('selectGridFSBucket')
            ->with($bucketOptions)
            ->willReturn($bucket);

        $database
            ->expects($this->once())
            ->method('selectCollection')
            ->with('fs.files')
            ->willReturn($collection);

        /** @var Client&MockObject */
        $client = $this->createMock(Client::class);
        $client
            ->method('selectDatabase')
            ->with('database-name')
            ->willReturn($database);

        $adapter = new GridFS('database-name', 'uri', [], [], $bucketOptions, $client);

        $this->assertTrue(
            $adapter->store($this->user, $this->imageId, 'image data'),
            'Expected adapter to store image',
        );
    }

    public function testCanDeleteImage(): void
    {
        /** @var Bucket&MockObject */
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('findOne')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
            ])
            ->willReturn(new BSONDocument(['_id' => 'document id']));

        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with('document id');

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);

        $this->assertTrue(
            $adapter->delete($this->user, $this->imageId),
            'Expected adapter to delete image',
        );
    }

    public function testDeleteThrowsExceptionWhenFileDoesNotExist(): void
    {
        /** @var Bucket&MockObject */
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('findOne')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
            ])
            ->willReturn(null);

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $adapter->delete($this->user, $this->imageId);
    }

    public function testCanGetImage(): void
    {
        $stream = fopen('php://temp', 'w');

        if (!$stream) {
            $this->fail('Unable to open stream');
        }

        fwrite($stream, 'image data');
        rewind($stream);

        /** @var Bucket&MockObject */
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('openDownloadStreamByName')
            ->with('user.image-id')
            ->willReturn($stream);

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $this->assertSame(
            'image data',
            $adapter->getImage($this->user, $this->imageId),
        );
    }

    #[DataProvider('getGetImageExceptions')]
    public function testGetImageThrowsExceptionWhenErrorOccurs(MongoDBException $mongoDbException, StorageException $storageException): void
    {
        /** @var Bucket&MockObject */
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('openDownloadStreamByName')
            ->willThrowException($mongoDbException);

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $this->expectExceptionObject($storageException);
        $adapter->getImage($this->user, $this->imageId);
    }

    public function testCanGetLastModified(): void
    {
        $time = time();

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $this->createConfiguredMock(Bucket::class, [
                    'findOne' => new BSONDocument(['metadata' => new BSONDocument(['updated' => $time])]),
                ]),
            ]),
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $this->assertEquals(
            new DateTime('@' . $time, new DateTimeZone('UTC')),
            $adapter->getLastModified($this->user, $this->imageId),
        );
    }

    public function testGetLastModifiedCanFail(): void
    {
        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $this->createConfiguredMock(Bucket::class, [
                    'findOne' => null,
                ]),
            ]),
        ]);

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $adapter->getLastModified($this->user, $this->imageId);
    }

    /**
     * @return array<array{mongoDbException:MongoDBException,storageException:StorageException}>
     */
    public static function getGetImageExceptions(): array
    {
        return [
            [
                'mongoDbException' => new FileNotFoundException('some error'),
                'storageException' => new StorageException('File not found', 404),
            ],
            [
                'mongoDbException' => new DriverRuntimeException('some error'),
                'storageException' => new StorageException('Unable to get image', 500),
            ],
        ];
    }
}
