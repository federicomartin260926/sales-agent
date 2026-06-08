<?php

namespace App\Repository;

use App\Entity\Conversation;
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

    public function findOneByExternalMessageId(?string $externalMessageId): ?ConversationMessage
    {
        if ($externalMessageId === null) {
            return null;
        }

        $externalMessageId = trim($externalMessageId);
        if ($externalMessageId === '') {
            return null;
        }

        return $this->createQueryBuilder('m')
            ->join('m.conversation', 'c')
            ->addSelect('c')
            ->andWhere('m.externalMessageId = :externalMessageId')
            ->setParameter('externalMessageId', $externalMessageId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ConversationMessage>
     */
    public function findRecentByConversation(Conversation $conversation, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));

        $messages = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!is_array($messages)) {
            return [];
        }

        return array_reverse(array_values(array_filter(
            $messages,
            static fn ($message): bool => $message instanceof ConversationMessage,
        )));
    }
}
