<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Infrastructure\Property\Normalizer;

use Neos\Utility\TypeHandling;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @api these normalizers are used for property serialization; and you can rely on their presence
 */
final class ScalarNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @param array<string,mixed> $context
     * @return int|float|bool|string
     */
    public function normalize($object, ?string $format = null, array $context = []): mixed
    {
        return $object;
    }

    public function supportsNormalization($data, ?string $format = null)
    {
        $type = TypeHandling::getTypeForValue($data);
        return TypeHandling::isSimpleType($type) && !TypeHandling::isCollectionType($type);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function denormalize($data, $type, ?string $format = null, array $context = [])
    {
        return $data;
    }

    public function supportsDenormalization($data, $type, ?string $format = null)
    {
        return TypeHandling::isSimpleType($type) && !TypeHandling::isCollectionType($type);
    }
}
