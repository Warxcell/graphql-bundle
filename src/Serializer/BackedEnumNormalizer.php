<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Serializer;

use ArrayObject;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer as Decorated;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class BackedEnumNormalizer implements NormalizerInterface, DenormalizerInterface, CacheableSupportsMethodInterface
{
    private Decorated $decorated;

    public function __construct(Decorated $decorated)
    {
        $this->decorated = $decorated;
    }

    public function hasCacheableSupportsMethod(): bool
    {
        return $this->decorated->hasCacheableSupportsMethod();
    }

    public function denormalize($data, string $type, string $format = null, array $context = [])
    {
        // here $data is already enum, because its converted by webonyx GraphQL
        if ($data instanceof $type) {
            return $data;
        }

        return $this->decorated->denormalize($data, $type, $format, $context);
    }

    public function supportsDenormalization($data, string $type, string $format = null): bool
    {
        return $this->decorated->supportsDenormalization($data, $type, $format);
    }

    public function normalize(
        $object,
        string $format = null,
        array $context = []
    ): float|int|bool|ArrayObject|array|string|null {
        return $this->decorated->normalize($object, $format, $context);
    }

    public function supportsNormalization($data, string $format = null): bool
    {
        return $this->decorated->supportsNormalization($data, $format);
    }
}
