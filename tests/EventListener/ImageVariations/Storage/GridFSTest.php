<?php declare(strict_types=1);
namespace Imbo\EventListener\ImageVariations\Storage;

use Imbo\Exception\StorageException;
use MongoDB\Client;
use MongoDB\Database;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\GridFS\Bucket;
use MongoDB\GridFS\Exception\FileNotFoundException;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCanStoreImageVariation(): void
    {
        $bucketOptions = ['some' => 'option'];

        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('uploadFromStream')
            ->with(
                'user.image-id.100',
                $this->isResource(),
                $this->callback(
                    fn (array $data): bool =>
                        is_int($data['metadata']['added'] ?? null) &&
                        $this->user === ($data['metadata']['user'] ?? null) &&
                        $this->imageId === ($data['metadata']['imageIdentifier'] ?? null) &&
                        100 === ($data['metadata']['width'] ?? null),
                ),
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

        $adapter = new GridFS('database-name', bucketOptions: $bucketOptions, client: $client);
        $adapter->storeImageVariation($this->user, $this->imageId, 'image data', 100);
    }

    public function testCanGetImageVariation(): void
    {
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
            ->with('user.image-id.100')
            ->willReturn($stream);

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', client: $client);
        $this->assertSame(
            'image data',
            $adapter->getImageVariation($this->user, $this->imageId, 100),
        );
    }

    #[DataProvider('getGetImageExceptions')]
    public function testGetImageVariationThrowsExceptionWhenErrorOccurs(MongoDBException $mongoDbException, StorageException $storageException): void
    {
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

        $adapter = new GridFS('database-name', client: $client);
        $this->expectExceptionObject($storageException);
        $adapter->getImageVariation($this->user, $this->imageId, 100);
    }

    public function testCanDeleteImageVariations(): void
    {
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('find')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
            ])
            ->willReturn([
                new BSONDocument(['_id' => 'id1']),
                new BSONDocument(['_id' => 'id2']),
            ]);

        $bucket
            ->expects($this->exactly(2))
            ->method('delete')
            ->with($this->callback(function ($foo) {
                static $i = 0;
                return match ([$i++, $foo]) {
                    [0, 'id1'],
                    [1, 'id2'] => true,
                    default => false,
                };
            }));

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', client: $client);
        $adapter->deleteImageVariations($this->user, $this->imageId);
    }

    public function testCanDeleteSpecificImageVariation(): void
    {
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('find')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
                'metadata.width'           => 100,
            ])
            ->willReturn([new BSONDocument(['_id' => 'document id'])]);

        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with('document id');

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', client: $client);
        $adapter->deleteImageVariations($this->user, $this->imageId, 100);
    }

    public function testDeleteThrowsExceptionWhenFileDoesNotExist(): void
    {
        $bucket = $this->createMock(Bucket::class);
        $bucket
            ->expects($this->once())
            ->method('find')
            ->with([
                'metadata.user'            => $this->user,
                'metadata.imageIdentifier' => $this->imageId,
            ])
            ->willReturn([new BSONDocument(['_id' => 'id'])]);

        $bucket
            ->expects($this->once())
            ->method('delete')
            ->with('id')
            ->willThrowException(new DriverRuntimeException('some error'));

        $client = $this->createConfiguredMock(Client::class, [
            'selectDatabase' => $this->createConfiguredMock(Database::class, [
                'selectGridFSBucket' => $bucket,
            ]),
        ]);

        $adapter = new GridFS('database-name', client: $client);

        $this->expectExceptionObject(new StorageException('Unable to delete image variations', 500));
        $adapter->deleteImageVariations($this->user, $this->imageId);
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
