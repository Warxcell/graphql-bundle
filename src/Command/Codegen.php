<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Command;

use Arxy\GraphQL\Codegen\Generator;
use Arxy\GraphQL\Module;
use GraphQL\Error\SyntaxError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'arxy:graphql:codegen')]
final class Codegen extends Command
{
    /**
     * @param iterable<Module> $modules
     */
    public function __construct(
        private readonly iterable $modules
    ) {
        parent::__construct();
    }

    /**
     * @throws SyntaxError
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $generator = new Generator($this->modules);
        $generator->execute();

        return 1;
    }
}

