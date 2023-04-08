<?php declare(strict_types=1);
namespace Imbo\EventListener\ImageVariations\Database;

use Imbo\Exception\DatabaseException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\EventListener\ImageVariations\Database\MongoDB
 */
class MongoDBTest extends TestCase
{
    private Collection&MockObject $collection;
    private MongoDB $adapter;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);

        $this->adapter = new MongoDB(
            'imbo',
            'mongodb://localhost:27017',
            [],
            [],
            $this->createMock(Client::class),
            $this->collection,
        );
    }

    /**
     * @covers ::__construct
     * @covers ::storeImageVariationMetadata
     */
    public function testThrowsExceptionWhenUnableToStoreImageVariationMetadata(): void
    {
        $e = $this->createMock(MongoDBException::class);
        $this->collection
            ->method('insertOne')
            ->willThrowException($e);

        $this->expectExceptionObject(new DatabaseException('Unable to save image variation data', 500, $e));
        $this->adapter->storeImageVariationMetadata('user', 'image', 100, 200);
    }
}
