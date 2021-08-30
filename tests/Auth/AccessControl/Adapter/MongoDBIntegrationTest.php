<?php declare(strict_types=1);
namespace Imbo\Auth\AccessControl\Adapter;

use MongoDB\Client;

/**
 * @coversDefaultClass Imbo\Auth\AccessControl\Adapter\MongoDB
 */
class MongoDBIntegrationTest extends MutableAdapterTests
{
    private string $databaseName = 'imbo-auth-accesscontrol-adapter-mongodb-integration-test';

    protected function getAdapter(): MongoDB
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
