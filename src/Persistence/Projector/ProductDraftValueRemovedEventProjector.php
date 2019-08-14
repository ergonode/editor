<?php

/**
 * Copyright © Bold Brand Commerce Sp. z o.o. All rights reserved.
 * See license.txt for license details.
 */

declare(strict_types = 1);

namespace Ergonode\Editor\Persistence\Projector;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Ergonode\Attribute\Domain\Entity\AttributeId;
use Ergonode\Core\Domain\Entity\AbstractId;
use Ergonode\Editor\Domain\Event\ProductDraftValueRemoved;
use Ergonode\EventSourcing\Infrastructure\DomainEventInterface;
use Ergonode\EventSourcing\Infrastructure\Exception\ProjectorException;
use Ergonode\EventSourcing\Infrastructure\Exception\UnsupportedEventException;
use Ergonode\EventSourcing\Infrastructure\Projector\DomainEventProjectorInterface;

/**
 */
class ProductDraftValueRemovedEventProjector implements DomainEventProjectorInterface
{
    private const DRAFT_VALUE_TABLE = 'designer.draft_value';

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param DomainEventInterface $event
     *
     * @return bool
     */
    public function support(DomainEventInterface $event): bool
    {
        return $event instanceof ProductDraftValueRemoved;
    }

    /**
     * @param AbstractId           $aggregateId
     * @param DomainEventInterface $event
     *
     * @throws ProjectorException
     * @throws UnsupportedEventException
     * @throws \Doctrine\DBAL\ConnectionException
     */
    public function projection(AbstractId $aggregateId, DomainEventInterface $event): void
    {
        if (!$event instanceof ProductDraftValueRemoved) {
            throw new UnsupportedEventException($event, ProductDraftValueRemoved::class);
        }

        try {
            $this->connection->beginTransaction();
            $draftId = $aggregateId->getValue();
            $elementId = AttributeId::fromKey($event->getAttributeCode())->getValue();

            $this->delete($draftId, $elementId);
            $this->connection->commit();
        } catch (\Exception $exception) {
            $this->connection->rollBack();
            throw new ProjectorException($event, $exception);
        }
    }

    /**
     * @param string $draftId
     * @param string $elementId
     *
     * @throws DBALException
     * @throws \Doctrine\DBAL\Exception\InvalidArgumentException
     */
    private function delete(string $draftId, string $elementId): void
    {
        $this->connection->delete(
            self::DRAFT_VALUE_TABLE,
            [
                'draft_id' => $draftId,
                'element_id' => $elementId,
            ]
        );
    }
}
