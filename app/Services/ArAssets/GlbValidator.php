<?php

namespace App\Services\ArAssets;

use InvalidArgumentException;

class GlbValidator
{
    private const MAGIC = 0x46546C67;

    private const VERSION = 2;

    private const JSON_CHUNK = 0x4E4F534A;

    private const BIN_CHUNK = 0x004E4942;

    /**
     * @var array<int, int>
     */
    private const COMPONENT_SIZES = [
        5120 => 1,
        5121 => 1,
        5122 => 2,
        5123 => 2,
        5125 => 4,
        5126 => 4,
    ];

    /**
     * @var array<string, int>
     */
    private const ACCESSOR_COMPONENTS = [
        'SCALAR' => 1,
        'VEC2' => 2,
        'VEC3' => 3,
        'VEC4' => 4,
        'MAT2' => 4,
        'MAT3' => 9,
        'MAT4' => 16,
    ];

    /**
     * Validate a binary glTF 2.0 asset and all embedded resources it uses.
     */
    public function validate(string $contents): void
    {
        $maxBytes = (int) config('ar.assets.max_bytes', 10 * 1024 * 1024);

        if ($contents === '' || strlen($contents) > $maxBytes) {
            throw new InvalidArgumentException('The GLB file exceeds the allowed size.');
        }

        if (strlen($contents) < 20) {
            throw new InvalidArgumentException('The GLB header is incomplete.');
        }

        $header = unpack('Vmagic/Vversion/Vlength', substr($contents, 0, 12));

        if ($header === false
            || $header['magic'] !== self::MAGIC
            || $header['version'] !== self::VERSION) {
            throw new InvalidArgumentException('The file is not a glTF 2.0 binary asset.');
        }

        $declaredLength = $header['length'];

        if ($declaredLength !== strlen($contents) || $declaredLength < 20) {
            throw new InvalidArgumentException('The GLB declared length is invalid.');
        }

        [$document, $binary] = $this->readChunks($contents, $declaredLength);
        $this->validateDocument($document, $binary);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function readChunks(string $contents, int $declaredLength): array
    {
        $offset = 12;
        $json = null;
        $binary = '';
        $binaryChunkSeen = false;

        while ($offset < $declaredLength) {
            if ($declaredLength - $offset < 8) {
                throw new InvalidArgumentException('The GLB contains an incomplete chunk header.');
            }

            $chunkHeader = unpack('Vlength/Vtype', substr($contents, $offset, 8));
            $chunkLength = $chunkHeader['length'];
            $chunkType = $chunkHeader['type'];
            $offset += 8;

            if ($chunkLength % 4 !== 0 || $chunkLength > $declaredLength - $offset) {
                throw new InvalidArgumentException('The GLB contains an out-of-bounds chunk.');
            }

            $chunk = substr($contents, $offset, $chunkLength);
            $offset += $chunkLength;

            if ($chunkType === self::JSON_CHUNK) {
                if ($json !== null || $offset !== 12 + 8 + $chunkLength) {
                    throw new InvalidArgumentException('The GLB must contain one JSON chunk first.');
                }

                $json = rtrim($chunk, "\0 \t\r\n");

                try {
                    $document = json_decode(
                        $json,
                        true,
                        512,
                        JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                    );
                } catch (\JsonException $exception) {
                    throw new InvalidArgumentException('The GLB JSON chunk is malformed.', previous: $exception);
                }

                if (! is_array($document)) {
                    throw new InvalidArgumentException('The GLB JSON chunk must contain an object.');
                }
            } elseif ($chunkType === self::BIN_CHUNK) {
                if ($binaryChunkSeen) {
                    throw new InvalidArgumentException('The GLB contains multiple binary chunks.');
                }

                $binaryChunkSeen = true;
                $binary = $chunk;
            } else {
                throw new InvalidArgumentException('The GLB contains an unsupported chunk type.');
            }
        }

        if ($json === null) {
            throw new InvalidArgumentException('The GLB is missing its JSON chunk.');
        }

        return [$document, $binary];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateDocument(array $document, string $binary): void
    {
        if (($document['asset']['version'] ?? null) !== '2.0') {
            throw new InvalidArgumentException('The GLB must declare glTF version 2.0.');
        }

        $this->validateExtensions($document);
        $buffers = $this->validateBuffers($document, $binary);
        $bufferViews = $this->validateBufferViews($document, $buffers, strlen($binary));
        $accessors = $this->validateAccessors($document, $bufferViews);

        $this->validateImages($document, $bufferViews, $binary);
        $this->validateTextures($document, $this->list($document, 'images'), $this->list($document, 'samplers'));
        $this->validateMaterials($document);
        $this->validateMeshes($document, $accessors);
        $this->validateNodes($document);
        $this->validateScenes($document);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateExtensions(array $document): void
    {
        foreach (['extensionsUsed', 'extensionsRequired'] as $key) {
            $extensions = $document[$key] ?? [];

            if (! is_array($extensions)) {
                throw new InvalidArgumentException("The GLB {$key} value is malformed.");
            }

            foreach ($extensions as $extension) {
                if (! is_string($extension)) {
                    throw new InvalidArgumentException("The GLB {$key} value is malformed.");
                }

                if (in_array($extension, ['KHR_texture_basisu', 'EXT_texture_webp'], true)) {
                    throw new InvalidArgumentException('The GLB uses an unsupported texture extension.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, array{byteLength: int}>
     */
    private function validateBuffers(array $document, string $binary): array
    {
        $buffers = $this->list($document, 'buffers');

        if (count($buffers) > 1) {
            throw new InvalidArgumentException('External or multiple GLB buffers are not supported.');
        }

        foreach ($buffers as $buffer) {
            if (array_key_exists('uri', $buffer)) {
                throw new InvalidArgumentException('External buffer references are not allowed.');
            }

            $byteLength = $this->nonNegativeInteger($buffer['byteLength'] ?? null, 'buffer byte length');

            if ($byteLength > strlen($binary)) {
                throw new InvalidArgumentException('The GLB buffer exceeds its binary chunk.');
            }
        }

        if ($buffers === [] && $binary !== '') {
            throw new InvalidArgumentException('The GLB binary chunk has no declared buffer.');
        }

        return array_map(
            fn (array $buffer): array => [
                'byteLength' => $this->nonNegativeInteger($buffer['byteLength'] ?? null, 'buffer byte length'),
            ],
            $buffers,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array{byteLength: int}>  $buffers
     * @return array<int, array{buffer: int, byteOffset: int, byteLength: int, byteStride: int|null}>
     */
    private function validateBufferViews(array $document, array $buffers, int $binaryLength): array
    {
        $views = [];

        foreach ($this->list($document, 'bufferViews') as $view) {
            $buffer = $this->nonNegativeInteger($view['buffer'] ?? null, 'bufferView buffer');
            $byteOffset = $this->nonNegativeInteger($view['byteOffset'] ?? 0, 'bufferView offset');
            $byteLength = $this->nonNegativeInteger($view['byteLength'] ?? null, 'bufferView length');
            $byteStride = array_key_exists('byteStride', $view)
                ? $this->nonNegativeInteger($view['byteStride'], 'bufferView stride')
                : null;

            if (! array_key_exists($buffer, $buffers)
                || $byteOffset > $buffers[$buffer]['byteLength']
                || $byteLength > $buffers[$buffer]['byteLength'] - $byteOffset
                || $byteOffset > $binaryLength
                || $byteLength > $binaryLength - $byteOffset) {
                throw new InvalidArgumentException('The GLB bufferView exceeds its buffer bounds.');
            }

            if ($byteStride !== null && ($byteStride < 4 || $byteStride > 252 || $byteStride % 4 !== 0)) {
                throw new InvalidArgumentException('The GLB bufferView stride is invalid.');
            }

            $views[] = [
                'buffer' => $buffer,
                'byteOffset' => $byteOffset,
                'byteLength' => $byteLength,
                'byteStride' => $byteStride,
            ];
        }

        return $views;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array{buffer: int, byteOffset: int, byteLength: int, byteStride: int|null}>  $bufferViews
     * @return array<int, array{count: int, componentType: int, type: string, bufferView: int|null, byteOffset: int}>
     */
    private function validateAccessors(
        array $document,
        array $bufferViews,
    ): array {
        $accessors = [];

        foreach ($this->list($document, 'accessors') as $accessor) {
            if (array_key_exists('sparse', $accessor)) {
                throw new InvalidArgumentException('Sparse GLB accessors are not supported.');
            }

            $count = $this->nonNegativeInteger($accessor['count'] ?? null, 'accessor count');
            $componentType = $this->nonNegativeInteger($accessor['componentType'] ?? null, 'accessor component type');
            $type = $accessor['type'] ?? null;

            if (! array_key_exists($componentType, self::COMPONENT_SIZES)
                || ! is_string($type)
                || ! array_key_exists($type, self::ACCESSOR_COMPONENTS)) {
                throw new InvalidArgumentException('The GLB accessor type is unsupported.');
            }

            $bufferView = array_key_exists('bufferView', $accessor)
                ? $this->nonNegativeInteger($accessor['bufferView'], 'accessor bufferView')
                : null;
            $byteOffset = $this->nonNegativeInteger($accessor['byteOffset'] ?? 0, 'accessor offset');
            $elementLength = self::COMPONENT_SIZES[$componentType] * self::ACCESSOR_COMPONENTS[$type];

            if ($bufferView === null && $count > 0) {
                throw new InvalidArgumentException('The GLB accessor has no bufferView.');
            }

            if ($bufferView !== null) {
                if (! array_key_exists($bufferView, $bufferViews)) {
                    throw new InvalidArgumentException('The GLB accessor references an unknown bufferView.');
                }

                $view = $bufferViews[$bufferView];
                $stride = $view['byteStride'] ?? $elementLength;
                $availableBytes = $view['byteLength'] - $byteOffset;

                if ($stride < $elementLength
                    || $byteOffset > $view['byteLength']
                    || ($count > 0 && ($availableBytes < $elementLength
                        || $count > 1 && $stride > intdiv($availableBytes - $elementLength, $count - 1)))) {
                    throw new InvalidArgumentException('The GLB accessor exceeds its bufferView bounds.');
                }
            }

            foreach (['min', 'max'] as $range) {
                if (array_key_exists($range, $accessor)) {
                    $this->finiteList($accessor[$range], "accessor {$range}");
                }
            }

            $accessors[] = [
                'count' => $count,
                'componentType' => $componentType,
                'type' => $type,
                'bufferView' => $bufferView,
                'byteOffset' => $byteOffset,
            ];
        }

        return $accessors;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array{buffer: int, byteOffset: int, byteLength: int, byteStride: int|null}>  $bufferViews
     */
    private function validateImages(array $document, array $bufferViews, string $binary): void
    {
        foreach ($this->list($document, 'images') as $image) {
            if (array_key_exists('uri', $image)) {
                throw new InvalidArgumentException('External image references are not allowed.');
            }

            $mimeType = $image['mimeType'] ?? null;

            if (! in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
                throw new InvalidArgumentException('The GLB contains an unsupported texture format.');
            }

            $bufferView = $this->nonNegativeInteger($image['bufferView'] ?? null, 'image bufferView');

            if (! array_key_exists($bufferView, $bufferViews)) {
                throw new InvalidArgumentException('The GLB image references an unknown bufferView.');
            }

            $view = $bufferViews[$bufferView];
            $bytes = substr($binary, $view['byteOffset'], $view['byteLength']);
            [$width, $height] = $this->imageDimensions($bytes, $mimeType);
            $maxDimension = (int) config('ar.assets.max_texture_dimension', 2048);

            if ($width > $maxDimension || $height > $maxDimension) {
                throw new InvalidArgumentException('The GLB texture exceeds the allowed dimensions.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array<string, mixed>>  $images
     * @param  array<int, array<string, mixed>>  $samplers
     */
    private function validateTextures(array $document, array $images, array $samplers): void
    {
        foreach ($this->list($document, 'textures') as $texture) {
            $source = $this->nonNegativeInteger($texture['source'] ?? null, 'texture source');

            if (! array_key_exists($source, $images)) {
                throw new InvalidArgumentException('The GLB texture references an unknown image.');
            }

            if (array_key_exists('sampler', $texture)) {
                $sampler = $this->nonNegativeInteger($texture['sampler'], 'texture sampler');

                if (! array_key_exists($sampler, $samplers)) {
                    throw new InvalidArgumentException('The GLB texture references an unknown sampler.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateMaterials(array $document): void
    {
        foreach ($this->list($document, 'materials') as $material) {
            $pbr = $material['pbrMetallicRoughness'] ?? null;

            if ($pbr !== null && ! is_array($pbr)) {
                throw new InvalidArgumentException('The GLB material is malformed.');
            }

            if (is_array($pbr)) {
                foreach (['baseColorFactor', 'metallicFactor', 'roughnessFactor'] as $field) {
                    if (array_key_exists($field, $pbr)) {
                        $this->finiteListOrNumber($pbr[$field], "material {$field}");
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, array{count: int, componentType: int, type: string, bufferView: int|null, byteOffset: int}>  $accessors
     */
    private function validateMeshes(array $document, array $accessors): void
    {
        $triangleCount = 0;

        foreach ($this->list($document, 'meshes') as $mesh) {
            foreach ($this->list($mesh, 'primitives') as $primitive) {
                $attributes = $primitive['attributes'] ?? null;

                if (! is_array($attributes) || ! array_key_exists('POSITION', $attributes)) {
                    throw new InvalidArgumentException('The GLB mesh primitive has no POSITION accessor.');
                }

                foreach ($attributes as $accessor) {
                    $this->accessor($accessors, $accessor, 'mesh attribute');
                }

                $positionAccessor = $this->accessor($accessors, $attributes['POSITION'], 'POSITION accessor');
                $mode = array_key_exists('mode', $primitive)
                    ? $this->nonNegativeInteger($primitive['mode'], 'primitive mode')
                    : 4;

                if (! in_array($mode, [0, 1, 2, 3, 4, 5, 6], true)) {
                    throw new InvalidArgumentException('The GLB primitive mode is invalid.');
                }

                $count = $positionAccessor['count'];

                if (array_key_exists('indices', $primitive)) {
                    $indices = $this->accessor($accessors, $primitive['indices'], 'indices accessor');

                    if (! in_array($indices['componentType'], [5121, 5123, 5125], true)) {
                        throw new InvalidArgumentException('The GLB indices component type is invalid.');
                    }

                    $count = $indices['count'];
                }

                $triangleCount += match ($mode) {
                    4 => intdiv($count, 3),
                    5, 6 => max($count - 2, 0),
                    default => 0,
                };

                if ($triangleCount > (int) config('ar.assets.max_triangles', 100000)) {
                    throw new InvalidArgumentException('The GLB contains too many triangles.');
                }

                if (array_key_exists('material', $primitive)) {
                    $material = $this->nonNegativeInteger($primitive['material'], 'primitive material');

                    if (! array_key_exists($material, $this->list($document, 'materials'))) {
                        throw new InvalidArgumentException('The GLB primitive references an unknown material.');
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateNodes(array $document): void
    {
        $meshes = $this->list($document, 'meshes');
        $nodes = $this->list($document, 'nodes');

        foreach ($nodes as $node) {
            foreach (['mesh', 'skin', 'camera'] as $reference) {
                if (array_key_exists($reference, $node)) {
                    $index = $this->nonNegativeInteger($node[$reference], "node {$reference}");
                    $collection = match ($reference) {
                        'mesh' => $meshes,
                        'skin' => $this->list($document, 'skins'),
                        'camera' => $this->list($document, 'cameras'),
                    };

                    if (! array_key_exists($index, $collection)) {
                        throw new InvalidArgumentException("The GLB node references an unknown {$reference}.");
                    }
                }
            }

            if (array_key_exists('children', $node)) {
                foreach ($this->finiteList($node['children'], 'node children') as $child) {
                    $index = $this->nonNegativeInteger($child, 'node child');

                    if (! array_key_exists($index, $nodes)) {
                        throw new InvalidArgumentException('The GLB node references an unknown child.');
                    }
                }
            }

            foreach (['matrix' => 16, 'translation' => 3, 'rotation' => 4, 'scale' => 3] as $transform => $length) {
                if (! array_key_exists($transform, $node)) {
                    continue;
                }

                $values = $this->finiteList($node[$transform], "node {$transform}");

                if (count($values) !== $length) {
                    throw new InvalidArgumentException("The GLB node {$transform} transform is malformed.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function validateScenes(array $document): void
    {
        $nodes = $this->list($document, 'nodes');
        $scenes = $this->list($document, 'scenes');

        if (array_key_exists('scene', $document)) {
            $scene = $this->nonNegativeInteger($document['scene'], 'default scene');

            if (! array_key_exists($scene, $scenes)) {
                throw new InvalidArgumentException('The GLB default scene is invalid.');
            }
        }

        foreach ($scenes as $scene) {
            foreach ($this->finiteList($scene['nodes'] ?? [], 'scene nodes') as $node) {
                $index = $this->nonNegativeInteger($node, 'scene node');

                if (! array_key_exists($index, $nodes)) {
                    throw new InvalidArgumentException('The GLB scene references an unknown node.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, array<string, mixed>>
     */
    private function list(array $document, string $key): array
    {
        $value = $document[$key] ?? [];

        if (! is_array($value)) {
            throw new InvalidArgumentException("The GLB {$key} value is malformed.");
        }

        return array_values(array_map(function (mixed $item) use ($key): array {
            if (! is_array($item)) {
                throw new InvalidArgumentException("The GLB {$key} item is malformed.");
            }

            return $item;
        }, $value));
    }

    /**
     * @param  array<int, array{count: int, componentType: int, type: string, bufferView: int|null, byteOffset: int}>  $accessors
     * @return array{count: int, componentType: int, type: string, bufferView: int|null, byteOffset: int}
     */
    private function accessor(array $accessors, mixed $index, string $label): array
    {
        $index = $this->nonNegativeInteger($index, $label);

        if (! array_key_exists($index, $accessors)) {
            throw new InvalidArgumentException("The GLB {$label} is unknown.");
        }

        return $accessors[$index];
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("The GLB {$label} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @return array<int, int|float>
     */
    private function finiteList(mixed $value, string $label): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("The GLB {$label} must be an array.");
        }

        foreach ($value as $item) {
            $this->finiteNumber($item, $label);
        }

        return $value;
    }

    private function finiteListOrNumber(mixed $value, string $label): void
    {
        if (is_array($value)) {
            $this->finiteList($value, $label);

            return;
        }

        $this->finiteNumber($value, $label);
    }

    private function finiteNumber(mixed $value, string $label): void
    {
        if (is_bool($value) || ! is_int($value) && ! is_float($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("The GLB {$label} contains a non-finite value.");
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function imageDimensions(string $bytes, string $mimeType): array
    {
        if ($mimeType === 'image/png') {
            if (strlen($bytes) < 24 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new InvalidArgumentException('The embedded PNG texture is malformed.');
            }

            $dimensions = unpack('Nwidth/Nheight', substr($bytes, 16, 8));

            if ($dimensions === false || $dimensions['width'] < 1 || $dimensions['height'] < 1) {
                throw new InvalidArgumentException('The embedded PNG texture dimensions are invalid.');
            }

            return [$dimensions['width'], $dimensions['height']];
        }

        if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xff\xd8") {
            throw new InvalidArgumentException('The embedded JPEG texture is malformed.');
        }

        $offset = 2;

        while ($offset + 3 < strlen($bytes)) {
            if (ord($bytes[$offset]) !== 0xFF) {
                $offset++;

                continue;
            }

            while ($offset < strlen($bytes) && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }

            if ($offset >= strlen($bytes)) {
                break;
            }

            $marker = ord($bytes[$offset]);
            $offset++;

            if (in_array($marker, [0xD8, 0xD9, 0x01], true) || $marker >= 0xD0 && $marker <= 0xD7) {
                continue;
            }

            if ($offset + 1 >= strlen($bytes)) {
                break;
            }

            $length = unpack('nlength', substr($bytes, $offset, 2))['length'];

            if ($length < 2 || $offset + $length > strlen($bytes)) {
                throw new InvalidArgumentException('The embedded JPEG texture is malformed.');
            }

            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                if ($length < 7) {
                    throw new InvalidArgumentException('The embedded JPEG texture dimensions are invalid.');
                }

                $dimensions = unpack('nheight/nwidth', substr($bytes, $offset + 3, 4));

                if ($dimensions === false || $dimensions['width'] < 1 || $dimensions['height'] < 1) {
                    throw new InvalidArgumentException('The embedded JPEG texture dimensions are invalid.');
                }

                return [$dimensions['width'], $dimensions['height']];
            }

            $offset += $length;
        }

        throw new InvalidArgumentException('The embedded JPEG texture has no dimensions.');
    }
}
