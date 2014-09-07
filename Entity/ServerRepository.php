<?php

namespace Mmd\Bundle\McMonitorBundle\Entity;

use Doctrine\ORM\EntityRepository;

class ServerRepository extends EntityRepository
{
    public function findNextForCheck()
    {
        return $this->getEntityManager()
            ->createQuery(
                'SELECT s FROM MmdMcMonitorBundle:Server s ORDER BY s.checked ASC'
            )
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }
}
