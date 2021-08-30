<?php declare(strict_types=1);
namespace Imbo\Auth\AccessControl\Adapter;

use Imbo\Auth\AccessControl\GroupQuery;
use Imbo\Exception\DatabaseException;
use Imbo\Helpers\BSONToArray;
use Imbo\Model\Groups as GroupsModel;
use MongoDB\BSON\ObjectID as MongoId;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use MongoDB\Model\BSONArray;

/**
 * MongoDB access control adapter
 */
class MongoDB extends AbstractAdapter implements MutableAdapterInterface
{
    private Client $client;
    private string $databaseName;
    private Collection $aclCollection;
    private Collection $aclGroupCollection;
    private BSONToArray $bsonToArray;

    public const ACL_COLLECTION       = 'accesscontrol';
    public const ACL_GROUP_COLLECTION = 'accesscontrolgroup';

    /**
     * Class constructor
     *
     * @param string $databaseName Name of the database to use
     * @param string $uri URI to connect to
     * @param array<string, mixed> $uriOptions Options for the URI, sent to the MongoDB\Client instance
     * @param array<string, mixed> $driverOptions Additional options for the MongoDB\Client instance
     * @param Client $client Pre-configured client
     * @param Collection $aclCollection Pre-configured collection instance
     * @param Collection $aclGroupCollection Pre-configured collection instance
     * @param BSONToArray $bsonToArray Helper to recursively convert documents to arrays
     * @throws DatabaseException
     */
    public function __construct(
        string $databaseName           = 'imbo',
        string $uri                    = 'mongodb://localhost:27017',
        array $uriOptions              = [],
        array $driverOptions           = [],
        Client $client                 = null,
        Collection $aclCollection      = null,
        Collection $aclGroupCollection = null,
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

        $this->aclCollection = $aclCollection ?: $this->client->selectCollection(
            $this->databaseName,
            self::ACL_COLLECTION,
        );

        $this->aclGroupCollection = $aclGroupCollection ?: $this->client->selectCollection(
            $this->databaseName,
            self::ACL_GROUP_COLLECTION,
        );

        $this->bsonToArray = $bsonToArray ?: new BSONToArray();
    }

    public function getGroups(GroupQuery $query, GroupsModel $model): array
    {
        $filter = [];

        /** @var Cursor<array{name:string,resources:BSONArray}> */
        $cursor = $this->aclGroupCollection
            ->find($filter, [
                'skip' => ($query->getPage() - 1) * $query->getLimit(),
                'limit' => $query->getLimit(),
            ]);

        $groups = [];

        foreach ($cursor as $group) {
            /** @var array<string> */
            $groups[$group['name']] = $group['resources']->getArrayCopy();
        }

        $model->setHits($this->aclGroupCollection->countDocuments($filter));

        return $groups;
    }

    public function groupExists(string $groupName): bool
    {
        return null !== $this->aclGroupCollection->findOne([
            'name' => $groupName,
        ]);
    }

    public function getGroup(string $groupName): ?array
    {
        /** @var array{resources?:BSONArray} */
        $group = $this->aclGroupCollection->findOne([
            'name' => $groupName,
        ]);

        if (isset($group['resources'])) {
            /** @var array<string> */
            return $group['resources']->getArrayCopy();
        }

        return null;
    }

    public function getPrivateKey(string $publicKey): ?string
    {
        /** @var ?array{privateKey?:string} */
        $keyPair = $this->aclCollection->findOne([
            'publicKey' => $publicKey,
        ], [
            'projection' => [
                'privateKey' => 1,
            ],
        ]);

        if (null === $keyPair || !isset($keyPair['privateKey'])) {
            return null;
        }

        return $keyPair['privateKey'];
    }

    public function addKeyPair(string $publicKey, string $privateKey): bool
    {
        try {
            $this->aclCollection->insertOne([
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
                'acl' => [],
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to insert key', 500, $e);
        }

        return true;
    }

    public function deletePublicKey(string $publicKey): bool
    {
        try {
            $result = $this->aclCollection->deleteOne([
                'publicKey' => $publicKey,
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to delete key', 500, $e);
        }

        return (bool) $result->getDeletedCount();
    }

    public function updatePrivateKey(string $publicKey, string $privateKey): bool
    {
        try {
            $result = $this->aclCollection->updateOne([
                'publicKey' => $publicKey,
            ], [
                '$set' => [
                    'privateKey' => $privateKey,
                ],
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to update private key', 500, $e);
        }

        return (bool) $result->getMatchedCount();
    }

    public function getAccessRule(string $publicKey, $accessRuleId): ?array
    {
        $acl = $this->getAccessListForPublicKey($publicKey);

        foreach ($acl as $rule) {
            if ($rule['id'] === $accessRuleId) {
                return $rule;
            }
        }

        return null;
    }

    public function addAccessRule(string $publicKey, array $accessRule): string
    {
        $accessRule['id'] = new MongoId();

        try {
            $this->aclCollection->updateOne([
                'publicKey' => $publicKey,
            ], [
                '$push' => [
                    'acl' => $accessRule,
                ],
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to add access rule', 500, $e);
        }

        return (string) $accessRule['id'];
    }

    public function deleteAccessRule(string $publicKey, string $accessRuleId): bool
    {
        try {
            $result = $this->aclCollection->updateOne([
                'publicKey' => $publicKey,
            ], [
                '$pull' => [
                    'acl' => [
                        'id' => new MongoId($accessRuleId),
                    ],
                ],
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to delete access rule', 500, $e);
        }

        return (bool) $result->getModifiedCount();
    }

    public function addResourceGroup(string $groupName, array $resources = []): bool
    {
        try {
            $this->aclGroupCollection->insertOne([
                'name' => $groupName,
                'resources' => $resources,
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to add resource group', 500, $e);
        }

        return true;
    }

    public function updateResourceGroup(string $groupName, array $resources): bool
    {
        try {
            $this->aclGroupCollection->updateOne([
                'name' => $groupName,
            ], [
                '$set' => [
                    'resources' => $resources,
                ],
            ]);
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to update resource group', 500, $e);
        }

        return true;
    }

    public function deleteResourceGroup(string $groupName): bool
    {
        try {
            $result = $this->aclGroupCollection->deleteOne([
                'name' => $groupName,
            ]);

            if ($result->getDeletedCount()) {
                $this->aclCollection->updateMany([
                    'acl.group' => $groupName,
                ], [
                    '$pull' => [
                        'acl' => [
                            'group' => $groupName,
                        ],
                    ],
                ]);
            }
        } catch (MongoDBException $e) {
            throw new DatabaseException('Unable to delete resource group', 500, $e);
        }

        return (bool) $result->getDeletedCount();
    }

    public function publicKeyExists(string $publicKey): bool
    {
        return null !== $this->aclCollection->findOne([
            'publicKey' => $publicKey,
        ]);
    }

    public function getAccessListForPublicKey(string $publicKey): array
    {
        /** @var ?array{acl:array<array{id:MongoId,users:array<string>,resources:array<string>}>} */
        $keyPair = $this->aclCollection->findOne([
            'publicKey' => $publicKey,
        ], [
            'projection' => [
                'acl' => 1,
            ],
        ]);

        if (null === $keyPair || empty($keyPair['acl'])) {
            return [];
        }

        $rules = [];

        foreach ($keyPair['acl'] as $rule) {
            $rule['id'] = (string) $rule['id'];
            /** @var array{id:string,users:array<string>,resources:array<string>} */
            $rules[] = $this->bsonToArray->toArray($rule);
        }

        return $rules;
    }
}
