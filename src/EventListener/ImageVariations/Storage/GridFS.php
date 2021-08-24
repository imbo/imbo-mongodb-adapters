<?php declare(strict_types=1);
namespace Imbo\EventListener\ImageVariations\Storage;

use Imbo\Exception\StorageException;
use MongoDB\Client;
use MongoDB\GridFS\Bucket;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\GridFS\Exception\FileNotFoundException;

/**
 * GridFS (MongoDB) storage adapter for image variations
 */
class GridFS implements StorageInterface {
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
        string $databaseName = 'imbo_imagevariation_storage',
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

    public function storeImageVariation(string $user, string $imageIdentifier, string $blob, int $width) : bool {
        $this->bucket->uploadFromStream(
            $this->getImageFilename($user, $imageIdentifier, $width),
            $this->createStream($blob),
            [
                'metadata' => [
                    'added'           => time(),
                    'user'            => $user,
                    'imageIdentifier' => $imageIdentifier,
                    'width'           => $width,
                ],
            ]
        );

        return true;
    }

    public function getImageVariation(string $user, string $imageIdentifier, int $width) : ?string {
        try {
            return stream_get_contents($this->bucket->openDownloadStreamByName(
                $this->getImageFilename($user, $imageIdentifier, $width)
            )) ?: null;
        } catch (FileNotFoundException $e) {
            throw new StorageException('File not found', 404, $e);
        } catch (MongoDBException $e) {
            throw new StorageException('Unable to get image variation', 500, $e);
        }
    }

    public function deleteImageVariations(string $user, string $imageIdentifier, int $width = null) : bool {
        $filter = [
            'metadata.user'            => $user,
            'metadata.imageIdentifier' => $imageIdentifier
        ];

        if (null !== $width) {
            $filter['metadata.width'] = $width;
        }

        /** @var array<int, array{_id: string}> */
        $files = $this->bucket->find($filter);

        foreach ($files as $file) {
            try {
                $this->bucket->delete($file['_id']);
            } catch (MongoDBException $e) {
                throw new StorageException('Unable to delete image variations', 500, $e);
            }
        }

        return true;
    }

    /**
     * Create a stream for a string
     *
     * @param string $data The string to use in the stream
     * @throws StorageException
     * @return resource
     */
    private function createStream(string $data) {
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
     * Get the image variation filename
     *
     * @param string $user
     * @param string $imageIdentifier
     * @param int $width
     * @return string
     */
    private function getImageFilename(string $user, string $imageIdentifier, int $width) : string {
        return $user . '.' . $imageIdentifier . '.' . $width;
    }
}
