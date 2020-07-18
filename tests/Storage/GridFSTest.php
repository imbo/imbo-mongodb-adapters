<?php declare(strict_types=1);
namespace Imbo\Storage;

use DateTime;
use DateTimeZone;
use Imbo\Exception\StorageException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\GridFS\Bucket;
use MongoDB\GridFS\Exception\FileNotFoundException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\Storage\GridFS
 */
class GridFSTest extends TestCase {
    private string $user    = 'user';
    private string $imageId = 'image-id';

    /**
     * @covers ::__construct
     */
    public function testThrowsExceptionWhenInvalidUriIsSpecified() : void {
        $this->expectExceptionObject(new StorageException('Unable to connect to the database', 500));
        new GridFS('some-database', 'foo');
    }

    /**
     * @covers ::__construct
     * @covers ::store
     * @covers ::imageExists
     * @covers ::getImageFilename
     * @covers ::createStream
     */
    public function testCanStoreImageThatDoesNotAlreadyExist() : void {
        $bucketOptions = ['some' => 'option'];

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
                $this->isType('resource'),
                $this->callback(function(array $data) : bool {
                    return
                        $this->user === ($data['metadata']['user'] ?? null) &&
                        $this->imageId === ($data['metadata']['imageIdentifier'] ?? null) &&
                        is_int($data['metadata']['updated'] ?? null);
                })
            );

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('selectGridFSBucket')
            ->with($bucketOptions)
            ->willReturn($bucket);

        $client = $this->createMock(Client::class);
        $client
            ->expects($this->once())
            ->method('selectDatabase')
            ->with('database-name')
            ->willReturn($database);

        $adapter = new GridFS('database-name', 'uri', [], [], $bucketOptions, $client);

        $this->assertTrue(
            $adapter->store($this->user, $this->imageId, 'image data'),
            'Expected adapter to store image'
        );
    }

    /**
     * @covers ::store
     * @covers ::imageExists
     */
    public function testCanStoreImageThatAlreadyExist() : void {
        $bucketOptions = ['some' => 'option'];

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

        $collection = $this->createMock(Collection::class);
        $collection
            ->expects($this->once())
            ->method('updateOne')
            ->with(
                [
                    'metadata.user' => $this->user,
                    'metadata.imageIdentifier' => $this->imageId,
                ],
                $this->callback(function(array $data) : bool {
                    return is_int($data['$set']['metadata.updated'] ?? null);
                }
            ));

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

        $client = $this->createMock(Client::class);
        $client
            ->method('selectDatabase')
            ->with('database-name')
            ->willReturn($database);

        $adapter = new GridFS('database-name', 'uri', [], [], $bucketOptions, $client);

        $this->assertTrue(
            $adapter->store($this->user, $this->imageId, 'image data'),
            'Expected adapter to store image'
        );
    }

    /**
     * @covers ::delete
     * @covers ::getImageObject
     */
    public function testCanDeleteImage() : void {
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
            ])
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);

        $this->assertTrue(
            $adapter->delete($this->user, $this->imageId),
            'Expected adapter to delete image'
        );
    }

    /**
     * @covers ::delete
     */
    public function testDeleteThrowsExceptionWhenFileDoesNotExist() : void {
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
            ])
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $adapter->delete($this->user, $this->imageId);
    }

    /**
     * @covers ::getImage
     * @covers ::getImageFilename
     */
    public function testCanGetImage() : void {
        $stream = fopen('php://temp', 'w');

        if (!$stream) {
            $this->fail('Unable to open stream');
        }

        fwrite($stream, 'image data');
        rewind($stream);

        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('openDownloadStreamByName')
            ->with('user.image-id')
            ->willReturn($stream);

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ])
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $this->assertSame(
            'image data',
            $adapter->getImage($this->user, $this->imageId),
        );
    }

    /**
     * @return array<int, array{0: MongoDBException, 1: StorageException}>
     */
    public function getGetImageExceptions() : array {
        return [
            [
                new FileNotFoundException('some error'),
                new StorageException('File not found', 404),
            ],
            [
                new DriverRuntimeException('some error'),
                new StorageException('Unable to get image', 500),
            ]
        ];
    }

    /**
     * @dataProvider getGetImageExceptions
     * @covers ::getImage
     */
    public function testGetImageThrowsExceptionWhenErrorOccurs(MongoDBException $mongoDbException, StorageException $storageException) : void {
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('openDownloadStreamByName')
            ->willThrowException($mongoDbException);

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ])
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $this->expectExceptionObject($storageException);
        $adapter->getImage($this->user, $this->imageId);
    }

    /**
     * @covers ::getLastModified
     */
    public function testCanGetLastModified() : void {
        $time = time();

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $this->createConfiguredMock(Bucket::class, [
                    'findOne' => new BSONDocument(['metadata' => new BSONDocument(['updated' => $time])])
                ]),
            ])
        ]);

        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $this->assertEquals(
            new DateTime('@' . $time, new DateTimeZone('UTC')),
            $adapter->getLastModified($this->user, $this->imageId),
        );
    }

    /**
     * @covers ::getLastModified
     */
    public function testGetLastModifiedCanFail() : void {
        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $this->createConfiguredMock(Bucket::class, [
                    'findOne' => null,
                ]),
            ])
        ]);

        $this->expectExceptionObject(new StorageException('File not found', 404));
        $adapter = new GridFS('database-name', 'uri', [], [], [], $client);
        $adapter->getLastModified($this->user, $this->imageId);
    }
}
