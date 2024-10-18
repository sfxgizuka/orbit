<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Review;
use App\Security\Http\Protection\ResourceHandlerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @implements ProcessorInterface<Review, Review>
 */
final readonly class ReviewPersistProcessor implements ProcessorInterface
{
    /**
     * @param PersistProcessor $persistProcessor
     */
    public function __construct(
        #[Autowire(service: PersistProcessor::class)]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private ClockInterface $clock,
        private ResourceHandlerInterface $resourceHandler,
    ) {
    }

    /**
     * @param Review $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Review
    {
        // standard PUT
        if (isset($context['previous_data'])) {
            $data->user = $context['previous_data']->user;
            $data->publishedAt = $context['previous_data']->publishedAt;
        }

        // prevent overriding user, for instance from admin
        if ($operation instanceof Post) {
            /** @phpstan-ignore-next-line */
            $data->user = $this->security->getUser();
            $data->publishedAt = $this->clock->now();
        }

        // save entity
        $data = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        // create resource on OIDC server
        if ($operation instanceof Post) {
            // project specification: only create resource on OIDC server for known users (john.doe and chuck.norris)
            if (\in_array($data->user->email, ['john.doe@example.com', 'chuck.norris@example.com'], true)) {
                $this->resourceHandler->create($data, $data->user, [
                    'operation_name' => '/books/{bookId}/reviews/{id}{._format}',
                ]);
            }
        }

        return $data;
    }
}
