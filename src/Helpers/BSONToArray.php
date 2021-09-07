<?php declare(strict_types=1);
namespace Imbo\Helpers;

use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class BSONToArray
{
    /**
     * Convert to array, recursively
     *
     * @param mixed $document
     * @return mixed
     */
    public function toArray($document)
    {
        if ($this->isBSONModel($document)) {
            $document = $document->getArrayCopy();
        } elseif (!is_array($document)) {
            return $document;
        }

        $result = [];

        foreach ($document as $key => $value) {
            if ($this->isBSONModel($value)) {
                $value = $this->toArray($value->getArrayCopy());
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Check if the value is a valid BSON model
     *
     * @param mixed $value
     * @return bool
     */
    private function isBSONModel($value): bool
    {
        return ($value instanceof BSONDocument || $value instanceof BSONArray);
    }
}
