<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Arxy\GraphQL\Controller\ExecutorInterface;
use Arxy\GraphQL\Sentry\SentryMiddleware;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(SentryMiddleware::class)
        ->decorate(ExecutorInterface::class)
        ->args([
            '$executor' => service('.inner'),
        ]);;
};
