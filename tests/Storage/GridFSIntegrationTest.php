<?php declare(strict_types=1);
namespace Imbo\Storage;

use MongoDB\Client;

/**
 * @coversDefaultClass Imbo\Storage\GridFS
 * @group integration
 */
class GridFSIntegrationTest extends StorageTests
{
    private string $databaseName = 'imbo-mongodb-adapters-integration-test';

    protected function getAdapter(): GridFS
    {
        $uriOptions = array_filter([
            'username' => (string) getenv('MONGODB_USERNAME'),
            'password' => (string) getenv('MONGODB_PASSWORD'),
        ]);

        $uri = (string) getenv('MONGODB_URI');

        return new GridFS($this->databaseName, $uri, $uriOptions);
    }

    public function setUp(): void
    {
        parent::setUp();

        $uriOptions = array_filter([
            'username' => (string) getenv('MONGODB_USERNAME'),
            'password' => (string) getenv('MONGODB_PASSWORD'),
        ]);

        $uri = (string) getenv('MONGODB_URI');
        $client = new Client($uri, $uriOptions);
        $client->dropDatabase($this->databaseName);
    }
}
