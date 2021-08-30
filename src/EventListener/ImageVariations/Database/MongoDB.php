<?php declare(strict_types=1);
namespace Imbo\EventListener\ImageVariations\Database;

use Imbo\Exception\DatabaseException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\Model\BSONDocument;

/**
 * MongoDB database driver for the image variations
 */
class MongoDB implements DatabaseInterface
{
    private string $databaseName;
    private Client $client;
    private Collection $collection;

    public const IMAGE_VARIATION_COLLECTION = 'imagevariation';

    /**
     * Class constructor
     */
    public function __construct(
        string $databaseName = 'imbo',
        string $uri = 'mongodb://localhost:27017',
        array $uriOptions = [],
        array $driverOptions = [],
        Client $client = null,
        Collection $collection = null
    ) {
        $this->databaseName = $databaseName;

        try {
            $this->client = $client ?: new Client(
                $uri,
                $uriOptions,
                $driverOptions,
            );
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to connect to the database', 500, $e);
        }

        $this->collection = $collection ?: $this->client->selectCollection(
            $this->databaseName,
            self::IMAGE_VARIATION_COLLECTION,
        );
    }

    public function storeImageVariationMetadata(string $user, string $imageIdentifier, int $width, int $height): bool
    {
        try {
            $this->collection->insertOne([
                'added' => time(),
                'user' => $user,
                'imageIdentifier'  => $imageIdentifier,
                'width' => $width,
                'height' => $height,
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to save image variation data', 500, $e);
        }

        return true;
    }

    public function getBestMatch(string $user, string $imageIdentifier, int $width): ?array
    {
        $query = [
            'user' => $user,
            'imageIdentifier' => $imageIdentifier,
            'width' => [
                '$gte' => $width,
            ],
        ];

        /** @var ?BSONDocument */
        $result = $this->collection
            ->findOne($query, [
                'projection' => [
                    '_id' => false,
                    'width' => true,
                    'height' => true,
                ],
                'sort' => [
                    'width' => 1,
                ],
            ]);

        if (null === $result) {
            return null;
        }

        /** @var array{width:int,height:int} */
        return $result->getArrayCopy();
    }

    public function deleteImageVariations(string $user, string $imageIdentifier, int $width = null): bool
    {
        $query = [
            'user' => $user,
            'imageIdentifier' => $imageIdentifier,
        ];

        if ($width !== null) {
            $query['width'] = $width;
        }

        $this->collection->deleteMany($query);

        return true;
    }
}
