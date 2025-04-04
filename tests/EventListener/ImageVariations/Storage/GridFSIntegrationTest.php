<?php declare(strict_types=1);
namespace Imbo\EventListener\ImageVariations\Storage;

use MongoDB\Client;
use MongoDB\Driver\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(GridFS::class)]
#[Group('integration')]
class GridFSIntegrationTest extends TestCase
{
    private GridFS $adapter;
    private string $user         = 'user';
    private string $imageId      = 'image-id';
    private string $databaseName = 'imbo-mongodb-adapters-integration-test';
    private string $fixturesDir  = __DIR__ . '/../../../fixtures';

    protected function setUp(): void
    {
        $uriOptions = array_filter([
            'username' => (string) getenv('MONGODB_USERNAME'),
            'password' => (string) getenv('MONGODB_PASSWORD'),
        ]);

        $uri = (string) getenv('MONGODB_URI');
        $client = new Client($uri, $uriOptions);

        try {
            $client->getDatabase($this->databaseName)->command(['ping' => 1]);
        } catch (RuntimeException) {
            $this->markTestSkipped('MongoDB is not running, start it with `docker compose up -d`', );
        }

        $client->dropDatabase($this->databaseName);
        $this->adapter = new GridFS($this->databaseName, $uri, $uriOptions);
    }

    public function testCanIntegrateWithMongoDB(): void
    {
        foreach ([100, 200, 300] as $width) {
            $this->assertTrue(
                $this->adapter->storeImageVariation($this->user, $this->imageId, (string) file_get_contents($this->fixturesDir . '/test-image.png'), $width),
                sprintf('Expected adapter to store image with width %d', $width),
            );
        }

        foreach ([100, 200, 300] as $width) {
            $this->assertSame(
                (string) file_get_contents($this->fixturesDir . '/test-image.png'),
                $this->adapter->getImageVariation($this->user, $this->imageId, $width),
                'Expected images to match',
            );
        }

        $this->assertTrue(
            $this->adapter->deleteImageVariations($this->user, $this->imageId, 100),
            'Expected image variation with width 100 to be deleted',
        );

        $this->assertTrue(
            $this->adapter->deleteImageVariations($this->user, $this->imageId),
            'Expected the rest of the image variations to be deleted',
        );
    }
}
