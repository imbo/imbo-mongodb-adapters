<?php declare(strict_types=1);
namespace Imbo\Database;

use ArrayObject;
use Imbo\Model\Image;
use Imbo\Model\Images;
use Imbo\Resource\Images\Query;
use Imbo\Exception\DatabaseException;
use Imbo\Exception\DuplicateImageIdentifierException;
use Imbo\Helpers\BSONToArray;
use MongoDB\Client;
use MongoDB\Driver\Command;
use MongoDB\Driver\Cursor;
use MongoDB\Collection;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\Driver\Exception\WriteException;
use MongoDB\Model\BSONDocument;
use DateTime;

/**
 * MongoDB database driver for Imbo
 */
class MongoDB implements DatabaseInterface {
    private Client $client;
    private string $databaseName;
    private Collection $imageCollection;
    private Collection $shortUrlCollection;
    private BSONToArray $bsonToArray;

    public const IMAGE_COLLECTION      = 'image';
    public const SHORT_URLS_COLLECTION = 'shortUrl';

    /**
     * Class constructor
     *
     * @param string $databaseName Name of the database to use
     * @param string $uri URI to connect to
     * @param array<string, mixed> $uriOptions Options for the URI, sent to the MongoDB\Client instance
     * @param array<string, mixed> $driverOptions Additional options for the MongoDB\Client instance
     * @param Client $client Pre-configured client
     * @param Collection $imageCollection Pre-configured Collection instance for the images
     * @param Collection $shortUrlCollection Pre-configured Collection instance for the short URLs
     * @param BSONToArray $bsonToArray Helper to recursively convert documents to arrays
     * @throws DatabaseException
     *
     * @see https://docs.mongodb.com/php-library/v1.6/reference/method/MongoDBClient__construct/
     */
    public function __construct(
        string $databaseName           = 'imbo',
        string $uri                    = 'mongodb://localhost:27017',
        array $uriOptions              = [],
        array $driverOptions           = [],
        Client $client                 = null,
        Collection $imageCollection    = null,
        Collection $shortUrlCollection = null,
        BSONToArray $bsonToArray       = null
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

        $this->imageCollection = $imageCollection ?: $this->client->selectCollection(
            $this->databaseName,
            self::IMAGE_COLLECTION,
        );

        $this->shortUrlCollection = $shortUrlCollection ?: $this->client->selectCollection(
            $this->databaseName,
            self::SHORT_URLS_COLLECTION,
        );

        $this->bsonToArray = $bsonToArray ?: new BSONToArray();
    }

    public function insertImage(string $user, string $imageIdentifier, Image $image, bool $updateIfDuplicate = true) : bool {
        $now = time();

        if ($updateIfDuplicate && $this->imageExists($user, $imageIdentifier)) {
            try {
                $this->imageCollection->updateOne(
                    [
                        'user'            => $user,
                        'imageIdentifier' => $imageIdentifier,
                    ],
                    [
                        '$set' => [
                            'updated' => $now,
                        ],
                    ],
                );
            } catch (MongoDBException $e) {
                throw new DatabaseException('Unable to save image data', 500, $e);
            }

            return true;
        }

        $added   = $image->getAddedDate();
        $updated = $image->getUpdatedDate();

        $data = [
            'size'             => $image->getFilesize(),
            'user'             => $user,
            'imageIdentifier'  => $imageIdentifier,
            'extension'        => $image->getExtension(),
            'mime'             => $image->getMimeType(),
            'metadata'         => [],
            'added'            => $added   ? $added->getTimestamp()   : $now,
            'updated'          => $updated ? $updated->getTimestamp() : $now,
            'width'            => $image->getWidth(),
            'height'           => $image->getHeight(),
            'checksum'         => $image->getChecksum(),
            'originalChecksum' => $image->getOriginalChecksum(),
        ];

        try {
            $this->imageCollection->insertOne($data);
        } catch (WriteException $e) {
            if (11000 === $e->getCode()) {
                throw new DuplicateImageIdentifierException(
                    'Duplicate image identifier when attempting to insert image into DB.',
                    503
                );
            }

            throw new DatabaseException('Unable to save image data', 500, $e);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to save image data', 500, $e);
        }

        return true;
    }

    public function deleteImage(string $user, string $imageIdentifier) : bool {
        // Get image to potentially trigger an exception if the image does not exist
        $this->getImageData($user, $imageIdentifier);

        try {
            $this->imageCollection->deleteOne([
                'user'            => $user,
                'imageIdentifier' => $imageIdentifier,
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to delete image data', 500, $e);
        }

        return true;
    }

    public function updateMetadata(string $user, string $imageIdentifier, array $metadata) : bool {
        try {
            $this->imageCollection->updateOne(
                [
                    'user'            => $user,
                    'imageIdentifier' => $imageIdentifier,
                ],
                [
                    '$set' => [
                        'metadata' => array_merge(
                            $this->getMetadata($user, $imageIdentifier),
                            $metadata,
                        ),
                    ],
                ],
            );
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to update meta data', 500, $e);
        }

        return true;
    }

    public function getMetadata(string $user, string $imageIdentifier) : array {
        /** @var ?ArrayObject */
        $metadata = $this->getImageData($user, $imageIdentifier)['metadata'] ?? null;

        if (!$metadata instanceof ArrayObject) {
            throw new DatabaseException('Incorrect metadata for image', 500);
        }

        /** @var array<string, mixed> */
        return $this->bsonToArray->toArray($metadata->getArrayCopy());
    }

    public function deleteMetadata(string $user, string $imageIdentifier) : bool {
        // Get image to potentially trigger an exception if the image does not exist
        $this->getImageData($user, $imageIdentifier);

        try {
            $this->imageCollection->updateOne(
                [
                    'user'            => $user,
                    'imageIdentifier' => $imageIdentifier,
                ],
                [
                    '$set' => [
                        'metadata' => [],
                    ],
                ],
            );
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to delete meta data', 500, $e);
        }

        return true;
    }

    public function getImages(array $users, Query $query, Images $model) : array {
        $images    = [];
        $queryData = [];

        if (!empty($users)) {
            $queryData['user']['$in'] = $users;
        }

        $from = $query->getFrom();
        $to   = $query->getTo();

        if ($from || $to) {
            $tmp = [];

            if (null !== $from) {
                $tmp['$gte'] = $from;
            }

            if (null !== $to) {
                $tmp['$lte'] = $to;
            }

            $queryData['added'] = $tmp;
        }

        $imageIdentifiers = $query->getImageIdentifiers();

        if (!empty($imageIdentifiers)) {
            $queryData['imageIdentifier']['$in'] = $imageIdentifiers;
        }

        $checksums = $query->getChecksums();

        if (!empty($checksums)) {
            $queryData['checksum']['$in'] = $checksums;
        }

        $originalChecksums = $query->getOriginalChecksums();

        if (!empty($originalChecksums)) {
            $queryData['originalChecksum']['$in'] = $originalChecksums;
        }

        $sort = ['added' => -1];

        if (!empty($query->getSort())) {
            $sort = [];

            foreach ($query->getSort() as $s) {
                $sort[$s['field']] = ('asc' === $s['sort'] ? 1 : -1);
            }
        }

        $fields = array_fill_keys([
            'extension',
            'added',
            'checksum',
            'originalChecksum',
            'updated',
            'user',
            'imageIdentifier',
            'mime',
            'size',
            'width',
            'height',
        ], true);

        if ($query->getReturnMetadata()) {
            $fields['metadata'] = true;
        }

        try {
            $options = [
                'projection' => $fields,
                'limit'      => $query->getLimit(),
                'sort'       => $sort,
            ];

            if (($page = $query->getPage()) > 1) {
                $skip = $query->getLimit() * ($page - 1);
                $options['skip'] = $skip;
            }

            /** @var BSONDocument[] */
            $result = $this->imageCollection->find($queryData, $options);
            $model->setHits($this->imageCollection->countDocuments($queryData));
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to search for images', 500, $e);
        }

        foreach ($result as $image) {
            unset($image['_id']);
            $image['added']   = new DateTime('@' . (int) $image['added']);
            $image['updated'] = new DateTime('@' . (int) $image['updated']);

            /** @var array<string, mixed> */
            $images[]         = $this->bsonToArray->toArray($image->getArrayCopy());
        }

        return $images;
    }

    public function getImageProperties(string $user, string $imageIdentifier) : array {
        $data = $this->getImageData($user, $imageIdentifier, [
            'size',
            'width',
            'height',
            'mime',
            'extension',
            'added',
            'updated',
        ], [
            '_id',
        ]);

        /** @var array{size: int, width: int, height: int, mime: string, extension: string, added: int, updated: int} */
        return $data->getArrayCopy();
    }

    public function load(string $user, string $imageIdentifier, Image $image) : bool {
        $data = $this->getImageData($user, $imageIdentifier);

        $image
            ->setWidth((int) $data['width'])
            ->setHeight((int) $data['height'])
            ->setFilesize((int) $data['size'])
            ->setMimeType((string) $data['mime'])
            ->setExtension((string) $data['extension'])
            ->setAddedDate(new DateTime('@' . (int) $data['added']))
            ->setUpdatedDate(new DateTime('@' . (int) $data['updated']));

        return true;
    }

    public function getLastModified(array $users, string $imageIdentifier = null) : DateTime {
        $query = [];

        if (!empty($users)) {
            $query['user']['$in'] = $users;
        }

        if (null !== $imageIdentifier) {
            $query['imageIdentifier'] = $imageIdentifier;
        }

        try {
            /** @var ?BSONDocument */
            $data = $this->imageCollection->findOne($query, [
                'sort' => [
                    'updated' => -1,
                ],
                'projection' => [
                    'updated' => true,
                ],
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to fetch image data', 500, $e);
        }

        if (null === $data && null !== $imageIdentifier) {
            throw new DatabaseException('Image not found', 404);
        } else if (null === $data) {
            $data = ['updated' => time()];
        }

        return new DateTime('@' . (int) $data['updated']);
    }

    public function setLastModifiedNow(string $user, string $imageIdentifier) : DateTime {
        return $this->setLastModifiedTime($user, $imageIdentifier, new DateTime('@' . time()));
    }

    public function setLastModifiedTime(string $user, string $imageIdentifier, DateTime $time) : DateTime {
        if (!$this->imageExists($user, $imageIdentifier)) {
            throw new DatabaseException('Image not found', 404);
        }

        // @todo Check if mongodb throws not found exception on updateOne
        $this->imageCollection->updateOne(
            [
                'user'            => $user,
                'imageIdentifier' => $imageIdentifier,
            ],
            [
                '$set' => [
                    'updated' => $time->getTimestamp(),
                ],
            ],
        );

        return $time;
    }

    public function getNumImages(string $user = null) : int {
        $query = [];

        if (null !== $user) {
            $query['user'] = $user;
        }

        try {
            $result = $this->imageCollection->countDocuments($query);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to fetch information from the database', 500, $e);
        }

        return $result;
    }

    public function getNumBytes(string $user = null) : int {
        $pipeline = [];

        if (null !== $user) {
            $pipeline[] = [
                '$match' => [
                    'user' => $user,
                ],
            ];
        }

        $pipeline[] =[
            '$group' => [
                '_id' => null,
                'numBytes' => [
                    '$sum' => '$size',
                ],
            ],
        ];

        try {
            /** @var Cursor */
            $result = $this->imageCollection->aggregate($pipeline);

            /** @var BSONDocument[] */
            $docs = $result->toArray();
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to fetch information from the database', 500, $e);
        }

        return array_sum(array_map(function(BSONDocument $doc) : int {
            return (int) $doc['numBytes'];
        }, $docs));
    }

    public function getNumUsers() : int {
        try {
            $result = count($this->imageCollection->distinct('user'));
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to fetch information from the database', 500, $e);
        }

        return $result;
    }

    public function getStatus() : bool {
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

    public function getImageMimeType(string $user, string $imageIdentifier) : string {
        return (string) $this->getImageData($user, $imageIdentifier)['mime'];
    }

    public function imageExists(string $user, string $imageIdentifier) : bool {
        try {
            $this->getImageData($user, $imageIdentifier);
        } catch (DatabaseException $e) {
            if (404 === $e->getCode()) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function insertShortUrl(string $shortUrlId, string $user, string $imageIdentifier, string $extension = null, array $query = []) : bool {
        $data = [
            'shortUrlId'      => $shortUrlId,
            'user'            => $user,
            'imageIdentifier' => $imageIdentifier,
            'extension'       => $extension,
            'query'           => serialize($query),
        ];

        try {
            $this->shortUrlCollection->insertOne($data);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to create short URL', 500, $e);
        }

        return true;
    }

    public function getShortUrlId(string $user, string $imageIdentifier, string $extension = null, array $query = []) : ?string {
        try {
            /** @var ?BSONDocument */
            $result = $this->shortUrlCollection->findOne([
                'user'            => $user,
                'imageIdentifier' => $imageIdentifier,
                'extension'       => $extension,
                'query'           => serialize($query),
            ], [
                'shortUrlId' => true,
            ]);
        } catch (MongoDBException $e) {
            return null;
        }

        if (null === $result) {
            return null;
        }

        /** @var ?string */
        return $result['shortUrlId'] ?? null;
    }

    public function getShortUrlParams(string $shortUrlId) : ?array {
        try {
            /** @var ?BSONDocument */
            $result = $this->shortUrlCollection->findOne([
                'shortUrlId' => $shortUrlId,
            ], [
                '_id' => false
            ]);
        } catch (MongoDBException $e) {
            return null;
        }

        if (null === $result) {
            return null;
        }

        if (empty($result['query'])) {
            throw new DatabaseException('Missing query from result', 500);
        }

        /** @var array<string, string|string[]> */
        $result['query'] = unserialize((string) $result['query']);

        /**
         * @var array{
         *  user: string,
         *  imageIdentifier: string,
         *  extension: string,
         *  query: array<string, string|string[]>
         * }
        */
        return $result->getArrayCopy();
    }

    public function deleteShortUrls(string $user, string $imageIdentifier, string $shortUrlId = null) : bool {
        $query = [
            'user'            => $user,
            'imageIdentifier' => $imageIdentifier,
        ];

        if (null !== $shortUrlId) {
            $query['shortUrlId'] = $shortUrlId;
        }

        try {
            $this->shortUrlCollection->deleteMany($query);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to delete short URLs', 500, $e);
        }

        return true;
    }

    public function getAllUsers() : array {
        /** @var string[] */
        return $this->imageCollection->distinct('user');
    }

    /**
     * Get image data
     *
     * @param string $user
     * @param string $imageIdentifier
     * @param string[] $includeKeys Keys to include in the result
     * @param string[] $excludeKeys Keys to exclude from the result
     * @throws DatabaseException
     * @return BSONDocument
     */
    private function getImageData(string $user, string $imageIdentifier, array $includeKeys = [], array $excludeKeys = []) : BSONDocument {
        try {
            $image = $this->imageCollection->findOne(
                [
                    'user'            => $user,
                    'imageIdentifier' => $imageIdentifier,
                ],
                [
                    'projection' => array_merge(
                        array_fill_keys($includeKeys, true),
                        array_fill_keys($excludeKeys, false),
                    ),
                ],
            );
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to find image data', 500, $e);
        }

        if (null === $image) {
            throw new DatabaseException('Image not found', 404);
        }

        /** @var BSONDocument */
        return $image;
    }
}
