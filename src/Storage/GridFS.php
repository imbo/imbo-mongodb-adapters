<?php declare(strict_types=1);
namespace Imbo\Storage;

use DateTime;
use DateTimeZone;
use Imbo\Exception\StorageException;
use MongoDB\Client;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\GridFS\Bucket;
use MongoDB\GridFS\Exception\FileNotFoundException;
use MongoDB\Model\BSONDocument;

/**
 * GridFS (MongoDB) storage adapter for images
 */
class GridFS implements StorageInterface
{
    private string $databaseName;
    private Client $client;
    private Bucket $bucket;

    /**
     * Class constructor
     *
     * @param string $databaseName Name of the database to use
     * @param string $uri URI to connect to
     * @param array<string, mixed> $uriOptions Options for the URI, sent to the MongoDB\Client instance
     * @param array<string, mixed> $driverOptions Additional options for the MongoDB\Client instance
     * @param array<string, mixed> $bucketOptions Options for the bucket operations
     * @param Client $client Pre-configured client
     * @throws StorageException
     *
     * @see https://docs.mongodb.com/php-library/v1.6/reference/method/MongoDBClient__construct/
     * @see https://docs.mongodb.com/php-library/v1.6/reference/method/MongoDBDatabase-selectGridFSBucket/#
     */
    public function __construct(
        string $databaseName = 'imbo_storage',
        string $uri          = 'mongodb://localhost:27017',
        array $uriOptions    = [],
        array $driverOptions = [],
        array $bucketOptions = [],
        Client $client       = null
    ) {
        $this->databaseName = $databaseName;

        try {
            $this->client = $client ?: new Client(
                $uri,
                $uriOptions,
                $driverOptions,
            );
        } catch (MongoDBException $e) {
            throw new StorageException('Unable to connect to the database', 500, $e);
        }

        $this->bucket = $this->client
            ->selectDatabase($this->databaseName)
            ->selectGridFSBucket($bucketOptions);
    }

    public function store(string $user, string $imageIdentifier, string $imageData): bool
    {
        if ($this->imageExists($user, $imageIdentifier)) {
            $collectionName = $this->bucket->getBucketName() . '.files';
            $collection     = $this->client->selectDatabase($this->databaseName)->selectCollection($collectionName);
            $collection->updateOne([
                'metadata.user'            => $user,
                'metadata.imageIdentifier' => $imageIdentifier,
            ], [
                '$set' => [
                    'metadata.updated' => time(),
                ],
            ]);

            return true;
        }

        $this->bucket->uploadFromStream(
            $this->getImageFilename($user, $imageIdentifier),
            $this->createStream($imageData),
            [
                'metadata' => [
                    'user'            => $user,
                    'imageIdentifier' => $imageIdentifier,
                    'updated'         => time(),
                ],
            ],
        );

        return true;
    }

    public function delete(string $user, string $imageIdentifier): bool
    {
        $file = $this->getImageObject($user, $imageIdentifier);

        if (null === $file) {
            throw new StorageException('File not found', 404);
        }

        $this->bucket->delete($file['_id']);

        return true;
    }

    public function getImage(string $user, string $imageIdentifier): ?string
    {
        try {
            return stream_get_contents($this->bucket->openDownloadStreamByName(
                $this->getImageFilename($user, $imageIdentifier),
            )) ?: null;
        } catch (FileNotFoundException $e) {
            throw new StorageException('File not found', 404, $e);
        } catch (MongoDBException $e) {
            throw new StorageException('Unable to get image', 500, $e);
        }
    }

    public function getLastModified(string $user, string $imageIdentifier): DateTime
    {
        /** @var ?array{metadata: array{updated: int}} */
        $file = $this->getImageObject($user, $imageIdentifier);

        if (null === $file) {
            throw new StorageException('File not found', 404);
        }

        return new DateTime('@' . $file['metadata']['updated'], new DateTimeZone('UTC'));
    }

    public function getStatus(): bool
    {
        try {
            $result = $this->client
                ->getManager()
                ->executeCommand($this->databaseName, new Command(['serverStatus' => 1]));
            // @codeCoverageIgnoreStart
        } catch (MongoDBException $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return (bool) $result->getServer()->getInfo()['ok'];
    }

    public function imageExists(string $user, string $imageIdentifier): bool
    {
        return null !== $this->bucket->findOne([
            'metadata.user'            => $user,
            'metadata.imageIdentifier' => $imageIdentifier,
        ]);
    }

    /**
     * Get an image object
     *
     * @param string $user The user which the image belongs to
     * @param string $imageIdentifier The image identifier
     * @return ?BSONDocument Returns null if the file does not exist or the file as an object otherwise
     */
    protected function getImageObject(string $user, string $imageIdentifier): ?BSONDocument
    {
        /** @var ?BSONDocument */
        return $this->bucket->findOne([
            'metadata.user'            => $user,
            'metadata.imageIdentifier' => $imageIdentifier,
        ]);
    }

    /**
     * Create a stream for a string
     *
     * @param string $data The string to use in the stream
     * @throws StorageException
     * @return resource
     */
    private function createStream(string $data)
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            // @codeCoverageIgnoreStart
            throw new StorageException('Unable to open stream', 500);
            // @codeCoverageIgnoreEnd
        }

        fwrite($stream, $data);
        rewind($stream);

        return $stream;
    }

    /**
     * Get the image filename
     *
     * @param string $user
     * @param string $imageIdentifier
     * @return string
     */
    private function getImageFilename(string $user, string $imageIdentifier): string
    {
        return $user . '.' . $imageIdentifier;
    }
}
