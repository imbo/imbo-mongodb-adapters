<?php declare(strict_types=1);
namespace Imbo\Auth\AccessControl\Adapter;

use Imbo\Exception\DatabaseException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\Auth\AccessControl\Adapter\MongoDB
 */
class MongoDBTest extends TestCase
{
    /** @var Collection&MockObject */
    private Collection $aclCollection;
    /** @var Collection&MockObject */
    private Collection $aclGroupCollection;
    private MongoDB $adapter;

    protected function setUp(): void
    {
        $this->aclCollection = $this->createMock(Collection::class);
        $this->aclGroupCollection = $this->createMock(Collection::class);

        $this->adapter = new MongoDB(
            'imbo',
            'mongodb://localhost:27017',
            [],
            [],
            $this->createMock(Client::class),
            $this->aclCollection,
            $this->aclGroupCollection,
        );
    }

    /**
     * @covers ::__construct
     * @covers ::addKeyPair
     */
    public function testThrowsExceptionWhenUnableToAddKeyPair(): void
    {
        $this->aclCollection
            ->method('insertOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to insert key', 500, $e));
        $this->adapter->addKeyPair('pub', 'priv');
    }

    /**
     * @covers ::deletePublicKey
     */
    public function testThrowsExceptionWhenUnableToDeleteKeyPair(): void
    {
        $this->aclCollection
            ->method('deleteOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to delete key', 500, $e));
        $this->adapter->deletePublicKey('pub');
    }

    /**
     * @covers ::updatePrivateKey
     */
    public function testThrowsExceptionWhenUnableToUpdatePrivateKey(): void
    {
        $this->aclCollection
            ->method('updateOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to update private key', 500, $e));
        $this->adapter->updatePrivateKey('pub', 'priv');
    }

    /**
     * @covers ::addAccessRule
     */
    public function testThrowsExceptionWhenUnableToAddAccessRule(): void
    {
        $this->aclCollection
            ->method('updateOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to add access rule', 500, $e));
        $this->adapter->addAccessRule('pub', [
            'resources' => ['resource'],
            'users' => ['user'],
        ]);
    }

    /**
     * @covers ::deleteAccessRule
     */
    public function testThrowsExceptionWhenUnableToDeleteAccessRule(): void
    {
        $this->aclCollection
            ->method('updateOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to delete access rule', 500, $e));
        $this->adapter->deleteAccessRule('pub', 'ruleId');
    }

    /**
     * @covers ::addResourceGroup
     */
    public function testThrowsExceptionWhenUnableToAddResourceGroup(): void
    {
        $this->aclGroupCollection
            ->method('insertOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to add resource group', 500, $e));
        $this->adapter->addResourceGroup('group', ['group']);
    }

    /**
     * @covers ::updateResourceGroup
     */
    public function testThrowsExceptionWhenUnableToUpdateResourceGroup(): void
    {
        $this->aclGroupCollection
            ->method('updateOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to update resource group', 500, $e));
        $this->adapter->updateResourceGroup('group', ['group']);
    }

    /**
     * @covers ::deleteResourceGroup
     */
    public function testThrowsExceptionWhenUnableToDeleteResourceGroup(): void
    {
        $this->aclGroupCollection
            ->method('deleteOne')
            ->willThrowException($e = $this->createMock(MongoDBException::class));

        $this->expectExceptionObject(new DatabaseException('Unable to delete resource group', 500, $e));
        $this->adapter->deleteResourceGroup('group');
    }
}
