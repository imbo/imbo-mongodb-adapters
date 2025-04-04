<?php declare(strict_types=1);
namespace Imbo\Helpers;

use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BSONToArray::class)]
class BSONToArrayTest extends TestCase
{
    private BSONToArray $helper;

    public function setUp(): void
    {
        $this->helper = new BSONToArray();
    }

    #[DataProvider('getValues')]
    public function testCanConvertValuesToArray(mixed $document, mixed $expected): void
    {
        $this->assertSame($expected, $this->helper->toArray($document));
    }

    /**
     * @return array<string,array{document:mixed,expected:mixed}>
     */
    public static function getValues(): array
    {
        return [
            'string value' => [
                'document' => 'string',
                'expected' => 'string',
            ],
            'integer value' => [
                'document' => 1,
                'expected' => 1,
            ],
            'float value' => [
                'document' => [1.1],
                'expected' => [1.1],
            ],
            'true boolean value' => [
                'document' => true,
                'expected' => true,
            ],
            'false boolean value' => [
                'document' => false,
                'expected' => false,
            ],
            'list value' => [
                'document' => [1, 2],
                'expected' => [1, 2],
            ],
            'simple bson-array' => [
                'document' => new BSONArray([1, 2, 3]),
                'expected' => [1, 2, 3],
            ],
            'simple bson-document' => [
                'document' => new BSONDocument([
                    'integer' => 1,
                    'string' => 'string',
                    'boolean' => true,
                    'double' => 1.1,
                ]),
                'expected' => [
                    'integer' => 1,
                    'string' => 'string',
                    'boolean' => true,
                    'double' => 1.1,
                ],
            ],
            'nested bson-document' => [
                'document' => new BSONDocument([
                    'list' => new BSONArray([1, 2, 3]),
                    'document' => new BSONDocument([
                        'list' => new BSONArray([1, 2, 3]),
                        'document' => new BSONDocument([
                            'foo' => 'bar',
                        ]),
                    ]),
                ]),
                'expected' => [
                    'list' => [1, 2, 3],
                    'document' => [
                        'list' => [1, 2, 3],
                        'document' => [
                            'foo' => 'bar',
                        ],
                    ],
                ],
            ],
        ];
    }
}
