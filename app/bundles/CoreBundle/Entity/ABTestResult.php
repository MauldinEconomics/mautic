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

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Class ABTestResult.
 */
class ABTestResult
{
    /** @var int */
    protected $id;

    /** @var int */
    protected $entityId;

    /** @var string */
    protected $entityType;

    /** @var array */
    protected $result = [];

    /**
     * @param ORM\ClassMetadata $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata)
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('abtest_results')
            ->addUniqueConstraint(['entity_id', 'entity_type'], 'abtest_result_entity')
            ->setCustomRepositoryClass('Mautic\CoreBundle\Entity\ABTestResultRepository');

        $builder->addId();

        $builder->addNamedField('entityId', Type::INTEGER, 'entity_id')
            ->addNamedField('entityType', Type::STRING, 'entity_type')
            ->addNullableField('result', Type::JSON_ARRAY);
    }

    /**
     * Get the 'type' (short classname) of entity.
     *
     * @param VariantEntityInterface $entity
     *
     * @return string
     */
    public static function typeOfEntity(VariantEntityInterface $entity)
    {
        return (new \ReflectionClass($entity))->getShortName();
    }

    /**
     * Get id.
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get Entity id.
     *
     * @return int
     */
    public function getEntityId()
    {
        return $this->entityId;
    }

    /**
     * Set Entity id.
     *
     * @param int $entityId
     *
     * @return $this
     */
    public function setEntityId($entityId)
    {
        $this->entityId = $entityId;

        return $this;
    }

    /**
     * Get Entity type.
     *
     * @return string
     */
    public function getEntityType()
    {
        return $this->entityType;
    }

    /**
     * Set Entity type.
     *
     * @param string $entityType
     *
     * @return $this
     */
    public function setEntityType($entityType)
    {
        $this->entityType = $entityType;

        return $this;
    }

    /**
     * Set Entity.
     *
     * @param mixed $entity
     *
     * @return $this
     */
    public function setEntity(VariantEntityInterface $entity)
    {
        return $this->setEntityId($entity->getId())
            ->setEntityType(self::typeOfEntity($entity));
    }

    /**
     * Get Result.
     *
     * @return array
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Set Result.
     *
     * @param array $result
     *
     * @return $this
     */
    public function setResult(array $result)
    {
        $this->result = $result;

        return $this;
    }
}
