<?php
declare(strict_types=1);

namespace Arxy\GraphQL\Controller;

use Arxy\GraphQL\QueryContainerFactory;
use Arxy\GraphQL\QueryError;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Language\Visitor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class JsonAPI
{
    /**
     * @var array<string, string>
     */
    private array $queries;

    /**
     * @var array<string, string>
     */
    private array $mutations;

    public function __construct(
        private ExecutorInterface $executor,
        private QueryContainerFactory $queryContainerFactory,
        string $queriesDirectory,
        string $fragmentsDirectory,
        private ?ContextFactoryInterface $contextFactory = null,
    ) {
        $dir = new \DirectoryIterator($fragmentsDirectory);
        $fragments = [];
        $fragmentGraph = [];
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }

            $documentNode = Parser::parse(file_get_contents($fileinfo->getRealPath()));

            $stack = [];
            Visitor::visit($documentNode, [
                'enter' => [
                    NodeKind::FRAGMENT_DEFINITION => function (FragmentDefinitionNode $definitionNode) use (
                        &$fragments,
                        &$fragmentGraph,
                        &$stack
                    ) {
                        $stack[] = $definitionNode->name->value;
                        $fragments[$definitionNode->name->value] = Printer::doPrint($definitionNode);
                    },
                    NodeKind::FRAGMENT_SPREAD => function (FragmentSpreadNode $definitionNode) use (
                        &$fragmentGraph,
                        &$fragments,
                        &$stack
                    ) {
                        $fragmentGraph[end($stack)][] = $definitionNode->name->value;
                    },
                ],
                'leave' => [
                    NodeKind::FRAGMENT_DEFINITION => function (FragmentDefinitionNode $definitionNode) use (&$stack) {
                        array_pop($stack);
                    },
                ],
            ]);
        }


        $queries = [];
        $mutation = [];
        $dir = new \DirectoryIterator($queriesDirectory);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }

            $operationName = $fileinfo->getBasename('.'.$fileinfo->getExtension());
            $document = file_get_contents($fileinfo->getRealPath());
            $documentNode = Parser::parse($document);

            $fragmentsForOperation = [];

            $operationType = null;
            Visitor::visit($documentNode, [
                'enter' => [
                    NodeKind::OPERATION_DEFINITION => function (OperationDefinitionNode $definitionNode) use (
                        &
                        $operationType
                    ) {
                        $operationType = $definitionNode->operation;
                    },
                    NodeKind::FRAGMENT_SPREAD => function (FragmentSpreadNode $definitionNode) use (
                        &$fragmentsForOperation,
                        &$fragmentGraph,
                        &$fragments
                    ) {
                        $pending = [$definitionNode->name->value];

                        while ($pending !== []) {
                            $fragmentName = array_pop($pending);
                            $fragmentsForOperation[$fragmentName] = $fragments[$fragmentName];

                            foreach ($fragmentGraph[$fragmentName] ?? [] as $fragment) {
                                $pending[] = $fragment;
                            }
                        }
                    },
                ],
            ]);

            $fragmentsDocuments = implode(PHP_EOL, $fragmentsForOperation);
            $fullDocument = $document.PHP_EOL.$fragmentsDocuments;

            switch ($operationType) {
                case 'query':
                    $queries[$operationName] = $fullDocument;
                    break;
                case 'mutation':
                    $mutation[$operationName] = $fullDocument;
                    break;
                default:
                    throw new \LogicException('Invalid operation type: '.$operationType);
            }

        }

        $this->queries = $queries;
        $this->mutations = $mutation;
    }

    public function __invoke(Request $request, string $operationName): Response
    {
        switch ($request->getMethod()) {
            case 'GET':
                if (!isset($this->queries[$operationName])) {
                    throw new NotFoundHttpException(sprintf('Operation "%s" not found.', $operationName));
                }

                $operation = $this->queries[$operationName];
                $variables = $request->query->all();
                break;
            case 'POST':
                if (!isset($this->mutations[$operationName])) {
                    throw new NotFoundHttpException(sprintf('Operation "%s" not found.', $operationName));
                }

                $operation = $this->mutations[$operationName];
                $variables = $request->request->all();
                break;
            default:
                throw new MethodNotAllowedHttpException(['GET', 'POST']);
        }

        try {
            $queryContainer = $this->queryContainerFactory->create($operation, null, $variables);
        } catch (QueryError $error) {
            return new JsonResponse([
                'errors' => $error->errors,
            ]);
        }

        $context = $this->contextFactory?->createContext($queryContainer, $request);
        $result = $this->executor->execute($queryContainer, $context);

        $response = new Response();
//        $response->setStatusCode(
//            $result->errors !== [] && $result->errors[0]->getPrevious() instanceof CoercionError ? 400 : 200
//        );
        $response->setContent(json_encode($result, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
