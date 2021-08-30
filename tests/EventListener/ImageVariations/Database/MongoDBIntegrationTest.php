<?php declare(strict_types=1);
namespace Imbo\EventListener\ImageVariations\Database;

use MongoDB\Client;

/**
 * @coversDefaultClass Imbo\EventListener\ImageVariations\Database\MongoDB
 */
class MongoDBIntegrationTest extends DatabaseTests
{
    private string $databaseName = 'imbo-imagevariations-integration-test';

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
    }
}
