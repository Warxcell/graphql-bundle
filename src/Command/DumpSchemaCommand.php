<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Command;

use Arxy\GraphQL\SchemaBuilder;
use GraphQL\Type\Schema;
use GraphQL\Utils\SchemaPrinter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Exception\LogicException;

use function file_put_contents;

#[AsCommand('graphql:dump-schema')]
class DumpSchemaCommand extends Command
{
    public function __construct(
        private readonly SchemaBuilder $schemaBuilder,
        private readonly string|null $location,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->location) {
            throw new LogicException('Please configure location in order to use this command');
        }
        file_put_contents($this->location, SchemaPrinter::doPrint($this->schemaBuilder->makeSchema()));

        return 0;
    }
}
