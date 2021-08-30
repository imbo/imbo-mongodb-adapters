<?php declare(strict_types=1);
namespace Imbo\Database;

use MongoDB\Client;

/**
 * @coversDefaultClass Imbo\Database\MongoDB
 * @group integration
 */
class MongoDBIntegrationTest extends DatabaseTests
{
    private string $databaseName = 'imbo-database-mongodb-integration-test';

    protected function getAdapter(): DatabaseInterface
    {
        return new MongoDB(
            $this->databaseName,
            (string) getenv('MONGODB_URI'),
            array_filter([
                'username' => (string) getenv('MONGODB_USERNAME'),
                'password' => (string) getenv('MONGODB_PASSWORD'),
            ]),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $uriOptions = array_filter([
            'username' => (string) getenv('MONGODB_USERNAME'),
            'password' => (string) getenv('MONGODB_PASSWORD'),
        ]);

        $uri = (string) getenv('MONGODB_URI');
        $client = new Client($uri, $uriOptions);
        $client->dropDatabase($this->databaseName);
        $client->selectCollection($this->databaseName, MongoDB::IMAGE_COLLECTION)->createIndex([
            'user'            => 1,
            'imageIdentifier' => 1,
        ], [
            'unique' => true,
        ]);
    }
}
