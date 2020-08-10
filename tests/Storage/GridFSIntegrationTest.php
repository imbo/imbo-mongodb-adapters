<?php declare(strict_types=1);
namespace Imbo\Storage;

use MongoDB\Client;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass Imbo\Storage\GridFS
 * @group integration
 */
class GridFSIntegrationTest extends TestCase {
    private GridFS $adapter;
    private string $user         = 'user';
    private string $imageId      = 'image-id';
    private string $databaseName = 'imbo-mongodb-adapters-integration-test';
    private string $fixturesDir  = __DIR__ . '/../fixtures';

    public function setUp() : void {
        $uriOptions = array_filter([
            'username' => (string) getenv('MONGODB_USERNAME'),
            'password' => (string) getenv('MONGODB_PASSWORD'),
        ]);

        $uri = (string) getenv('MONGODB_URI');
        $client = new Client($uri, $uriOptions);
        $client->dropDatabase($this->databaseName);

        $this->adapter = new GridFS($this->databaseName, $uri, $uriOptions);
    }

    /**
     * @covers ::getStatus
     * @covers ::imageExists
     * @covers ::store
     * @covers ::getLastModified
     * @covers ::imageExists
     * @covers ::getImage
     * @covers ::delete
     */
    public function testCanIntegrateWithMongoDB() : void {
        $this->assertTrue(
            $this->adapter->getStatus(),
            'Expected status to be true',
        );

        $this->assertFalse(
            $this->adapter->imageExists($this->user, $this->imageId),
            'Did not expect image to exist',
        );

        $this->assertTrue(
            $this->adapter->store($this->user, $this->imageId, (string) file_get_contents($this->fixturesDir . '/test-image.png')),
            'Expected adapter to store image',
        );

        $this->assertEqualsWithDelta(
            (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp(),
            $this->adapter->getLastModified($this->user, $this->imageId)->getTimestamp(),
            5,
            'Expected timestamps to be equal',
        );

        $this->assertTrue(
            $this->adapter->imageExists($this->user, $this->imageId),
            'Expected image to exist',
        );

        $this->assertSame(
            (string) file_get_contents($this->fixturesDir . '/test-image.png'),
            $this->adapter->getImage($this->user, $this->imageId),
            'Expected images to match'
        );

        $this->assertTrue(
            $this->adapter->delete($this->user, $this->imageId),
            'Expected image to be deleted',
        );

        $this->assertFalse(
            $this->adapter->imageExists($this->user, $this->imageId),
            'Did not expect image to exist',
        );
    }
}
