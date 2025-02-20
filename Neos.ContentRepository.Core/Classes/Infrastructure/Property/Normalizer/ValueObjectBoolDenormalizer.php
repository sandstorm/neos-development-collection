<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Infrastructure\Property\Normalizer;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * @api these normalizers are used for property serialization; and you can rely on their presence
 */
final class ValueObjectBoolDenormalizer implements DenormalizerInterface
{
    /**
     * @param array<string,mixed> $context
     */
    public function denormalize($data, $type, ?string $format = null, array $context = [])
    {
        return $type::fromBool($data);
    }

    public function supportsDenormalization($data, $type, ?string $format = null): bool
    {
        return is_bool($data) && class_exists($type) && method_exists($type, 'fromBool');
    }
}
