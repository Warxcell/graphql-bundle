<?php

declare(strict_types=1);

namespace Arxy\GraphQL\Sentry;

use Arxy\GraphQL\Events\OnExecute;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class SentryEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HubInterface $hub
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OnExecute::class => 'onExecute',
        ];
    }

    public function onExecute(OnExecute $event): void
    {
        $this->hub->configureScope(static function (Scope $scope) use ($event): void {
            $scope->setContext('GraphQL', [
                'operationName' => $event->operationName,
                'operationType' => $event->operationType,
            ]);

            $scope->setExtra('document', $event->query);
            $scope->setExtra('variables', $event->variables);
        });
    }
}
