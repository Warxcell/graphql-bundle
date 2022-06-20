<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Utils\AST;

use function file_exists;
use function file_put_contents;
use function var_export;

final class CachedDocumentNodeProvider implements DocumentNodeProviderInterface
{
    public function __construct(
        private readonly DocumentNodeProviderInterface $documentNodeProvider,
        private readonly string $cacheFile,
    ) {
    }

    /**
     * @throws SyntaxError
     */
    public function getDocumentNode(): DocumentNode
    {
        if (file_exists($this->cacheFile)) {
            $documentNode = AST::fromArray(require $this->cacheFile);
        } else {
            $dir = dirname($this->cacheFile);
            if (!is_dir($dir)) {
                mkdir($dir);
            }
            $documentNode = $this->documentNodeProvider->getDocumentNode();
            file_put_contents(
                $this->cacheFile,
                "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(AST::toArray($documentNode), true) . ";\n"
            );
        }

        assert($documentNode instanceof DocumentNode);

        return $documentNode;
    }
}
