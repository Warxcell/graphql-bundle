<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Command\DebugResolversCommand;
use Arxy\GraphQL\Debug\TimingMiddleware;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(TimingMiddleware::class);

    $services->set(DebugResolversCommand::class);
};
