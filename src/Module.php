<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

interface Module
{
    public static function getSchema(): string;

    public static function getCodegenNamespace(): string;

    public static function getCodegenDirectory(): string;

    public static function getTypeMapping(): array;
}
