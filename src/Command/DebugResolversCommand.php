<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function explode;
use function is_array;
use function sprintf;
use function str_contains;
use function str_repeat;

#[AsCommand('debug:graphql:resolvers')]
class DebugResolversCommand extends Command
{
    public function __construct(
        /**
         * @var array<string, array<string, array<int, string|array{string, string}>>>
         */
        private readonly array $resolversInfo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('filter', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $filter = $input->getArgument('filter');

        $objectFilter = null;
        $fieldFilter = null;
        if ($filter) {
            if (str_contains($filter, '.')) {
                [$objectFilter, $fieldFilter] = explode('.', $filter);
            } else {
                $objectFilter = $filter;
            }
        }

        $table = $symfonyStyle->createTable();
        $table->setHeaders(['Location', 'Resolver']);
        foreach ($this->resolversInfo as $graphqlName => $fields) {
            if ($objectFilter && !str_contains($graphqlName, $objectFilter)) {
                continue;
            }

            foreach ($fields as $field => $resolverOrMiddlewares) {
                if ($fieldFilter && !str_contains($field, $fieldFilter)) {
                    continue;
                }

                foreach ($resolverOrMiddlewares as $i => $resolverOrMiddleware) {
                    $separator = str_repeat('-', $i);
                    if (is_array($resolverOrMiddleware)) {
                        [$serviceId, $method] = $resolverOrMiddleware;
                    } else {
                        $serviceId = $resolverOrMiddleware;
                        $method = '__invoke';
                    }

                    $table->addRow([
                        $i === 0 ? $graphqlName.'.'.$field : null,
                        sprintf('%s %s::%s', $separator, $serviceId, $method),
                    ]);
                }
                $table->addRow(new TableSeparator());
            }
        }

        $table->render();

        return 0;
    }
}
