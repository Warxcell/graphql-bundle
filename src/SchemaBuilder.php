<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use Closure;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use LogicException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function assert;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function var_export;

use const PHP_EOL;

/**
 * https://github.com/webonyx/graphql-php/issues/500
 */
final class SchemaBuilder
{
    /**
     * @param iterable<string> $schemas
     */
    public function __construct(
        private readonly iterable $schemas,
        private readonly string $cacheDir,
        private readonly bool $debug
    ) {
    }

    /**
     * @throws SyntaxError
     * @throws Error
     */
    public function makeSchema(?Closure $typeConfigDecorator = null): Schema
    {
        if ($this->debug && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir);
        }

        $cacheFile = $this->cacheDir . '/schema.php';

        if ($this->debug || !file_exists($cacheFile)) {
            $schemaContent = file_get_contents(__DIR__ . '/Resources/graphql/schema.graphql');

            foreach ($this->schemas as $schema) {
                $schemaContent .= file_get_contents($schema) . PHP_EOL;
            }

            $document = Parser::parse($schemaContent);
            file_put_contents(
                $cacheFile,
                "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(AST::toArray($document), true) . ";\n"
            );

            $finalSchema = Printer::doPrint($document);
            file_put_contents($this->cacheDir . '/schema.graphql', $finalSchema);
        } else {
            $document = AST::fromArray(require $cacheFile);
        }

        $nonExtendDefs = [];
        $extendDefs = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof TypeExtensionNode) {
                $extendDefs[] = $definition;
            } else {
                $nonExtendDefs[] = $definition;
            }
        }

        $options = [
            'assumeValid' => !$this->debug,
            'assumeValidSDL' => !$this->debug,
        ];
        $schema = BuildSchema::build(
            new DocumentNode(['definitions' => $nonExtendDefs]),
            $typeConfigDecorator,
            $options
        );

        return SchemaExtender::extend(
            $schema,
            new DocumentNode(['definitions' => $extendDefs]),
            $options,
            $typeConfigDecorator
        );
    }

    /**
     * @param array<string, array<string, object>> $resolvers
     * @throws Error
     * @throws SyntaxError
     * TODO make executableSchema SOLID again (move codegen features (convert args from array to objects),serializer,security,validator to plugins/extensions/whatever)
     */
    public function makeExecutableSchema(
        array $resolvers,
        array $argumentsMapping,
        array $enums,
        DenormalizerInterface $serializer,
        Security $security,
        ValidatorInterface $validator
    ): Schema {
        $resolver = static function (mixed $objectValue, mixed $args, mixed $contextValue, ResolveInfo $info) use (
            $argumentsMapping,
            $resolvers,
            $serializer,
            $validator,
            $security
        ) {
            $isGrantedDirective = DirectiveHelper::getDirectiveValues('isGranted', $info);

            if ($isGrantedDirective && !$security->isGranted($isGrantedDirective['role'])) {
                throw new AuthorizationError($isGrantedDirective['role']);
            }

            if (isset($argumentsMapping[$info->parentType->name][$info->fieldName])) {
                $args = $serializer->denormalize($args, $argumentsMapping[$info->parentType->name][$info->fieldName]);
                $errors = $validator->validate($args);
                if (count($errors) > 0) {
                    throw new ConstraintViolationException($errors);
                }
            }
            $objectResolver = $resolvers[$info->parentType->name][$info->fieldName] ?? throw new LogicException(sprintf('Could not resolve %s.%s', $info->parentType->name, $info->fieldName));

            return [$objectResolver, $info->fieldName]($objectValue, $args, $contextValue, $info);
        };

        $typeConfigDecorator = static function (
            array $typeConfig,
            TypeDefinitionNode $typeDefinitionNode,
            array $definitionMap
        ) use ($resolvers, $resolver, $enums) {
            $name = $typeConfig['name'];
            $typeResolvers = $resolvers[$name] ?? null;

            if ($typeDefinitionNode instanceof UnionTypeDefinitionNode
                || $typeDefinitionNode instanceof UnionTypeExtensionNode
                || $typeDefinitionNode instanceof InterfaceTypeDefinitionNode
                || $typeDefinitionNode instanceof InterfaceTypeExtensionNode
            ) {
                assert($typeResolvers !== null, sprintf('Missing resolvers for union/interface %s', $name));

                $resolveType = [$typeResolvers, 'resolveType'];

                $typeConfig['resolveType'] = static function ($objectValue, $context, ResolveInfo $info) use (
                    $resolveType,
                    $definitionMap
                ) {
                    $rawType = $resolveType($objectValue, $context, $info);

                    if (!$rawType) {
                        return null;
                    }

                    return $info->schema->getType($rawType);
                };
            } elseif ($typeDefinitionNode instanceof ScalarTypeDefinitionNode) {
                assert($typeResolvers !== null, sprintf('Missing resolvers for scalar %s', $name));

                $typeConfig['serialize'] = [$typeResolvers, 'serialize'];
                $typeConfig['parseValue'] = [$typeResolvers, 'parseValue'];
                $typeConfig['parseLiteral'] = [$typeResolvers, 'parseLiteral'];
            } elseif ($typeDefinitionNode instanceof ObjectTypeDefinitionNode || $typeDefinitionNode instanceof ObjectTypeExtensionNode) {
                assert($typeResolvers !== null, sprintf('Missing resolvers for %s', $name));

                $typeConfig['resolveField'] = $resolver;
            } elseif ($typeDefinitionNode instanceof EnumTypeDefinitionNode || $typeDefinitionNode instanceof EnumTypeExtensionNode) {
                $enum = $enums[$name] ?? null;
                assert($enum !== null, sprintf('Missing enum %s', $name));
                foreach ($typeConfig['values'] as $key => &$value) {
                    $value['value'] = $enum::from($key);
                }
            }

            return $typeConfig;
        };

        return $this->makeSchema($typeConfigDecorator);
    }
}
