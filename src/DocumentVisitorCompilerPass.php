<?php

declare(strict_types=1);

namespace Arxy\GraphQL;

use GraphQL\Language\AST\DocumentNode;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function assert;

abstract class DocumentVisitorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $documentProvider = $container->get(DocumentNodeProviderInterface::class);
        assert($documentProvider instanceof DocumentNodeProviderInterface);
        $this->processDocumentNode($container, $documentProvider->getDocumentNode());
    }

    abstract protected function processDocumentNode(ContainerBuilder $container, DocumentNode $documentNode): void;
}
