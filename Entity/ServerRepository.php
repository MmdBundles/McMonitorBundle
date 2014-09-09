<?php

namespace Mmd\Bundle\McMonitorBundle\Entity;

use Doctrine\ORM\EntityRepository;

class ServerRepository extends EntityRepository
{
    public function findNextForCheck($limit = 1)
    {
        return $this->getEntityManager()
            ->createQuery(
                'SELECT s FROM MmdMcMonitorBundle:Server s ORDER BY s.checked ASC'
            )
            ->setMaxResults($limit)
            ->getResult();
    }
}
