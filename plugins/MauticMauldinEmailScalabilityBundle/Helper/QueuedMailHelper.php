<?php

/*
 * @package     Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Max Lawton <max@mauldineconomics.com>
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Helper;

use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\MailHelper;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport\QueuedTransportInterface;

/**
 * Class QueuedMailHelper.
 */
class QueuedMailHelper extends MailHelper
{
    /**
     * Create an email stat.
     *
     * @param bool|true   $persist
     * @param string|null $emailAddress
     * @param null        $listId
     *
     * @return Stat|void
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function createEmailStat($persist = true, $emailAddress = null, $listId = null)
    {
        static $copies = [];

        //create a stat
        $stat = new Stat();
        $stat->setDateSent(new \DateTime());
        $stat->setEmail($this->email);

        // Note if a lead
        if (null !== $this->lead) {
            $stat->setLead($this->factory->getEntityManager()->getReference('MauticLeadBundle:Lead', $this->lead['id']));
            $emailAddress = $this->lead['email'];
        }

        // Find email if applicable
        if (null === $emailAddress) {
            // Use the last address set
            $emailAddresses = $this->message->getTo();

            if (count($emailAddresses)) {
                end($emailAddresses);
                $emailAddress = key($emailAddresses);
            }
        }
        $stat->setEmailAddress($emailAddress);

        // Note if sent from a lead list
        if (null !== $listId) {
            $stat->setList($this->factory->getEntityManager()->getReference('MauticLeadBundle:LeadList', $listId));
        }

        $stat->setTrackingHash($this->idHash);
        if (!empty($this->source)) {
            $stat->setSource($this->source[0]);
            $stat->setSourceId($this->source[1]);
        }

        $stat->setTokens($this->getTokens());

        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel = $this->factory->getModel('email');

        // CODE IGNORED WHEN USING THE RABBITMQ TRANSPORT
        if (!$this->transport instanceof QueuedTransportInterface) {

            // Save a copy of the email - use email ID if available simply to prevent from having to rehash over and over
            $id = (null !== $this->email) ? $this->email->getId() : md5($this->subject.$this->body['content']);
            if (!isset($copies[$id])) {
                $hash = (strlen($id) !== 32) ? md5($this->subject.$this->body['content']) : $id;

                $copy        = $emailModel->getCopyRepository()->findByHash($hash);
                $copyCreated = false;
                if (null === $copy) {
                    if (!$emailModel->getCopyRepository()->saveCopy($hash, $this->subject, $this->body['content'])) {
                        // Try one more time to find the ID in case there was overlap when creating
                        $copy = $emailModel->getCopyRepository()->findByHash($hash);
                    } else {
                        $copyCreated = true;
                    }
                }

                if ($copy || $copyCreated) {
                    $copies[$id] = $hash;
                }
            }

            if (isset($copies[$id])) {
                $stat->setStoredCopy($this->factory->getEntityManager()->getReference('MauticEmailBundle:Copy', $copies[$id]));
            }
        }
        // END OF CODE IGNORED

        if ($persist) {
            $emailModel->getStatRepository()->saveEntity($stat);
        }

        return $stat;
    }
}
