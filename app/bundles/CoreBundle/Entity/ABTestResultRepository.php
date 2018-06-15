<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * Class ABTestResultRepository.
 */
class ABTestResultRepository extends EntityRepository
{
    /**
     * Save an entity through the repository.
     *
     * @param object $entity
     * @param bool   $flush  true by default; use false if persisting in batches
     *
     * @return int
     */
    public function saveEntity($entity, $flush = true)
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush($entity);
        }
    }

    /**
     * Get result by entity.
     *
     * @param VariantEntityInterface $entity
     *
     * @return ABTestResult|null
     */
    public function findOneByEntity(VariantEntityInterface $entity)
    {
        return $this->findOneBy([
            'entityId'   => $entity->getId(),
            'entityType' => ABTestResult::typeOfEntity($entity),
        ]);
    }
}
