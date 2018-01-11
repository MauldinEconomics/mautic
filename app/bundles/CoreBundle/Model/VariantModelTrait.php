<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Model;

use Mautic\CoreBundle\Entity\TranslationEntityInterface;
use Mautic\CoreBundle\Entity\VariantEntityInterface;

/**
 * Class VariantModelTrait.
 */
trait VariantModelTrait
{
    /**
     * @var bool
     */
    protected $inConversion = false;

    /**
     * Converts a variant to the main item and the original main item a variant.
     *
     * @param VariantEntityInterface $entity
     */
    public function convertVariant(VariantEntityInterface $entity)
    {
        $this->recordAbResult($entity);

        //let saveEntities() know it does not need to set variant start dates
        $this->inConversion = true;

        list($parent, $children) = $entity->getVariants();

        $save = [];

        //set this email as the parent for the original parent and children
        if ($parent) {
            if ($parent->getId() != $entity->getId()) {
                if (method_exists($parent, 'setIsPublished')) {
                    $parent->setIsPublished(false);
                    $entity->setPublishUp($parent->getPublishUp());
                    $entity->setPublishDown($parent->getPublishDown());
                }

                $entity->addVariantChild($parent);
                $entity->removeVariantParent();
                $parent->setVariantParent($entity);
            }

            foreach ($children as $child) {
                //capture child before it's removed from collection
                if (!($child->getId() == $entity->getId())) {
                    $save[] = $child;
                }

                $parent->removeVariantChild($child);
            }
        }

        if (count($save)) {
            foreach ($save as $child) {
                if (method_exists($child, 'setIsPublished')) {
                    $child->setIsPublished(false);
                }

                $entity->addVariantChild($child);
                $child->setVariantParent($entity);
            }
        }

        $save[] = $parent;
        $save[] = $entity;

        //save the entities
        foreach($save as $entity) {
            $this->saveEntity($entity, false);
        }

    }

    private function recordAbResult($entity)
    {
        $abResults = $this->getWinnerVariant($entity);

        $conn = $this->em->getConnection();

        $q = <<<EOQ
INSERT INTO
        ab_test_result (entity_id, entity_type, result)
    VALUES
        (:id, :type, :value)
EOQ;

        $stmt = $conn->prepare($q);
        $stmt->bindValue('id', $entity->getId());
        $stmt->bindValue('type', (new \ReflectionClass($entity))->getShortName());
        $stmt->bindValue('value', json_encode($abResults));
        $stmt->execute();
    }

    public function getRecordedAbResult($entity)
    {
        $conn = $this->em->getConnection();

        $q = <<<EOQ
SELECT
        result
    FROM
        ab_test_result AS r
    WHERE
        r.entity_id = :id
            AND
        r.entity_type = :type
EOQ;

        $stmt = $conn->prepare($q);
        $stmt->bindValue('id', $entity->getId());
        $stmt->bindValue('type', (new \ReflectionClass($entity))->getShortName());
        $stmt->execute();
        $row = $stmt->fetch();

        if (!empty($row)) {
            $result = json_decode($row['result'], true);
            $result['isRecorded'] = true;
            return $result;
        }
        return null;
    }

    /**
     * Prepare a variant for saving.
     *
     * @param VariantEntityInterface $entity
     * @param array                  $resetVariantCounterMethods ['setVariantHits', 'setVariantSends', ...]
     * @param \DateTime|null         $variantStartDate
     */
    protected function preVariantSaveEntity(VariantEntityInterface $entity, array $resetVariantCounterMethods = [], \DateTime $variantStartDate = null)
    {
        $isVariant = $entity->isVariant();

        if (!$isVariant && $entity instanceof TranslationEntityInterface) {
            // Translations could be assigned to a variant and thus applicable to be reset
            if ($translationParent = $entity->getTranslationParent()) {
                $isVariant = $translationParent->isVariant();
            }
        }

        if ($isVariant) {
            // Reset the variant hit and start date if there are any changes and if this is an A/B test
            // Do it here in addition to postVariantSave() so that it's available to the event listeners
            $changes = $entity->getChanges();

            // If unpublished and wasn't changed from published - don't reset
            if (!$entity->isPublished(false) && (!isset($changes['isPublished']))) {
                return false;
            }

            // Reset the variant
            if (!empty($changes) && empty($this->inConversion)) {
                if (method_exists($entity, 'setVariantStartDate')) {
                    $entity->setVariantStartDate($variantStartDate);
                }

                // Reset counters
                foreach ($resetVariantCounterMethods as $method) {
                    if (method_exists($entity, $method)) {
                        $entity->$method(0);
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Run post saving a variant aware entity.
     *
     * @param VariantEntityInterface $entity
     * @param bool                   $resetVariants
     * @param array                  $relatedIds
     * @param \DateTime|null         $variantStartDate
     */
    protected function postVariantSaveEntity(VariantEntityInterface $entity, $resetVariants = false, $relatedIds = [], \DateTime $variantStartDate = null)
    {
        // If parent, add this entity as a child of the parent so that it populates the list in the tab (due to Doctrine hanging on to entities in memory)
        if ($parent = $entity->getVariantParent()) {
            $parent->addVariantChild($entity);
        }

        // Reset associated variants if applicable due to changes
        if ($resetVariants && empty($this->inConversion)) {
            $this->resetVariants($entity, $relatedIds, $variantStartDate);
        }
    }

    /**
     * @param           $entity
     * @param           $relatedIds
     * @param \DateTime $variantStartDate
     */
    protected function resetVariants($entity, $relatedIds = null, \DateTime $variantStartDate = null)
    {
        $repo = $this->getRepository();

        if (method_exists($repo, 'resetVariants')) {
            if (null == $relatedIds) {
                $relatedIds = $entity->getRelatedEntityIds();
            }

            if (!in_array($entity->getId(), $relatedIds)) {
                $relatedIds[] = $entity->getId();
            }

            if (null === $variantStartDate) {
                $variantStartDate = new \DateTime();
            }

            // Ensure UTC since we're saving directly to the DB
            $variantStartDate->setTimezone(new \DateTimeZone('UTC'));
            $repo->resetVariants($relatedIds, $variantStartDate->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Converts a variant to the main item and the original main item a variant.
     *
     * @param VariantEntityInterface $entity
     */
    public function getWinnerVariant($entity){
        //get A/B test information
        list($parent, $children) = $entity->getVariants();
        $properties              = [];
        $variantError            = false;
        $weight                  = 0;
        if (count($children)) {
            foreach ($children as $c) {
                $variantSettings = $c->getVariantSettings();

                if (is_array($variantSettings) && isset($variantSettings['winnerCriteria'])) {
                    if ($c->isPublished()) {
                        if (!isset($lastCriteria)) {
                            $lastCriteria = $variantSettings['winnerCriteria'];
                        }

                        //make sure all the variants are configured with the same criteria
                        if ($lastCriteria != $variantSettings['winnerCriteria']) {
                            $variantError = true;
                        }

                        $weight += $variantSettings['weight'];
                    }
                } else {
                    $variantSettings['winnerCriteria'] = '';
                    $variantSettings['weight']         = 0;
                }

                $properties[$c->getId()] = $variantSettings;
            }

            $properties[$parent->getId()]['weight']         = 100 - $weight;
            $properties[$parent->getId()]['winnerCriteria'] = '';
        }

        $abTestResults = [];
        $criteria      = $this->getBuilderComponents($entity, 'abTestWinnerCriteria');
        if (!empty($lastCriteria) && empty($variantError)) {
            if (isset($criteria['criteria'][$lastCriteria])) {
                $testSettings = $criteria['criteria'][$lastCriteria];

                $args = [
                    'factory'    => $this->factory,
                    'entity'     => $entity,
                    'parent'     => $parent,
                    'children'   => $children,
                    'properties' => $properties,
                ];

                //execute the callback
                if (is_callable($testSettings['callback'])) {
                    if (is_array($testSettings['callback'])) {
                        $reflection = new \ReflectionMethod($testSettings['callback'][0], $testSettings['callback'][1]);
                    } elseif (strpos($testSettings['callback'], '::') !== false) {
                        $parts      = explode('::', $testSettings['callback']);
                        $reflection = new \ReflectionMethod($parts[0], $parts[1]);
                    } else {
                        $reflection = new \ReflectionMethod(null, $testSettings['callback']);
                    }

                    $pass = [];
                    foreach ($reflection->getParameters() as $param) {
                        if (isset($args[$param->getName()])) {
                            $pass[] = $args[$param->getName()];
                        } else {
                            $pass[] = null;
                        }
                    }
                    $abTestResults = $reflection->invokeArgs($this, $pass);
                }
            }
        }
        return $abTestResults;
    }
}
