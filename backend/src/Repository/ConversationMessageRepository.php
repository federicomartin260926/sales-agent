<?php

namespace App\Repository;

use App\Entity\ConversationMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversationMessage>
 */
class ConversationMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationMessage::class);
    }

    public function save(ConversationMessage $conversationMessage, bool $flush = true): void
    {
        $this->getEntityManager()->persist($conversationMessage);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ConversationMessage $conversationMessage, bool $flush = true): void
    {
        $this->getEntityManager()->remove($conversationMessage);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
