<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Model;

use MauticPlugin\MauticMauldinCSIBundle\Model\SETRequestModel;
use \Doctrine\DBAL\Connection;

class SETListModel
{
    private $conn;
    private $setRequest;

    public function __construct(Connection $conn, SETRequestModel $setRequest)
    {
        $this->conn = $conn;
        $this->setRequest = $setRequest;
    }

    public function log_setlist($listId)
    {
        $q = "SELECT *, NOW() AS now FROM setlists AS l WHERE l.id = :id";

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->execute();
        $result = $stmt->fetchAll();
        echo('      Setlist: ' . json_encode($result) . PHP_EOL);
    }

    /*
     * @param int $listId: the id of the SET list
     * @param string $listName: the name of the SET list
     * @param $segment: the mautic segment which uses the SET list
     */
    public function upsertSetList($listId, $listName, $segment)
    {
        $q = <<<EOQ
INSERT INTO
        setlists (id, name, update_interval)
    VALUES
        (:id, :name, :interval)
    ON DUPLICATE KEY UPDATE
        name = :name,
        update_requested = 1,
        update_interval = CASE WHEN :interval < update_interval THEN :interval
                                ELSE update_interval END
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->bindValue('name', $listName);
        $stmt->bindValue('interval', $segment->getUpdateInterval());
        $stmt->execute();

        $this->log_setlist($listId);
    }

    /*
     * @param int $listId: the id of the SET list
     * @param $segment: the mautic segment which uses the SET list
     */
    public function requestCacheUpdate($listId, $segment)
    {
        $this->log_setlist($listId);

        // Check if a request has already been made
        $q = <<<EOQ
SELECT
        l.id
    FROM
        setlists AS l
    WHERE
        l.id = :id
            AND
        l.update_requested = 1
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->execute();
        $result = $stmt->fetchAll();

        if (! empty($result)) {
            echo('    Update already requested for: ' . $listId . PHP_EOL);
            return;
        }

        // Request list cache update
        $listName = $this->setRequest->requestCacheUpdate($listId);

        // Upsert local SET list
        $this->upsertSetList($listId, $listName, $segment);
    }

    /*
     * Returns an array with the ids of the SET lists for which a cache update
     * was requested.
     * @return array(int)
     */
    public function getUpdatableLists()
    {
        $q = <<<EOQ
SELECT
        l.id
    FROM
        setlists AS l
    WHERE
        l.update_requested = 1
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->execute();
        $result = $stmt->fetchAll();

        $listIds = [];
        foreach ($result as $row) {
            $listIds[] = $row['id'];
        }
        return $listIds;
    }

    /*
     * Checks if local cache is valid.
     *
     * It is valid if:
     *     "SET list exists locally"
     *         AND
     *     "now < last_update + update_interval"
     *
     * Since "last_update" is set with the value that came from the SET API
     * call, the Mautic database and the SET database need to be on the same
     * timezone.
     *
     * @param int listId: the id of the SET list
     *
     * @return boolean
     */
    public function isCacheValid($listId, $segment)
    {
        $this->log_setlist($listId);

        $q = <<<EOQ
SELECT
        l.id
    FROM
        setlists AS l
    WHERE
        l.id = :id
            AND
        DATE_ADD(l.last_update, INTERVAL
                CASE WHEN :interval < l.update_interval THEN :interval
                     ELSE l.update_interval END
            HOUR) > NOW()
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->bindValue('interval', $segment->getUpdateInterval());
        $stmt->execute();
        $result = $stmt->fetchAll();

        echo('    ' . $listId . ' cache valid: ' . json_encode(! empty($result)) . PHP_EOL);

        return ! empty($result);
    }

    /*
     * @param int $listId: the id of the SET list
     *
     * @return boolean: True if the cache was updated, False otherwise
     */
    public function maybeUpdateCache($listId)
    {
        echo('  Maybe update: ' . $listId . PHP_EOL);
        $this->log_setlist($listId);

        $lastUpdate = $this->_getLastUpdateDate($listId);

        // Check if new cache available
        $timeFinished = $this->setRequest->isNewerCacheAvailable($listId, $lastUpdate);
        if (null === $timeFinished) {
            echo('    No update available' . PHP_EOL);
            return false;
        }
        echo('    Newer cache available: ' . date("Y-m-d H:i:s", $timeFinished) . PHP_EOL);

        // Download new cache
        $emails = $this->setRequest->downloadCache($listId);

        if (null === $emails) {
            echo('    No email list' . PHP_EOL);
            return false;
        }

        // Save new cache
        $this->conn->beginTransaction();

        $this->_cleanSetListLeads($listId);
        foreach ($emails as $email) {
            $leadId = $this->_maybeAddNewLead($email);
            $this->_addSetListLead($listId, $leadId);
        }

        $this->conn->commit();

        // Update list
        $this->_setLastUpdateDate($listId, $timeFinished);

        $this->log_setlist($listId);

        return true;
    }

    /*
     * @param int $listId: the id of the SET list
     *
     * @return datetime: The last update date of the list
     */
    public function _getLastUpdateDate($listId)
    {
        $q = <<<EOQ
SELECT
        l.last_update
    FROM
        setlists AS l
    WHERE
        l.id = :id
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->execute();
        $result = $stmt->fetch();

        return strtotime($result['last_update']);
    }

    /*
     * @param int $listId: the id of the SET list
     *
     * Updates the last update date and unsets the requested update flag.
     */
    public function _setLastUpdateDate($listId, $timeFinished)
    {
        $q = <<<EOQ
UPDATE
        setlists AS l
    SET
        l.last_update = :time,
        l.update_requested = 0
    WHERE
        l.id = :id
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->bindValue('time', date("Y-m-d H:i:s", $timeFinished));
        $stmt->execute();
    }

    /*
     * @param int $listId: the id of the SET list
     *
     * Removes all leads from the SET list cache
     */
    public function _cleanSetListLeads($listId)
    {
        $q = <<<EOQ
DELETE FROM setlists_leads
WHERE
    setlist_id = :id
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('id', $listId);
        $stmt->execute();
    }

    /*
     * @param string $email: the lead email
     *
     * @return int: the id of the lead
     */
    public function _maybeAddNewLead($email)
    {
        $id = $this->_findLead($email);

        if (null !== $id) {
            return $id;
        }

        return $this->_addNewLead($email);
    }

    /*
     * @param string $email: the lead email
     *
     * @return maybe(int): the id of the lead or null
     */
    public function _findLead($email)
    {
        $q = <<<EOQ
SELECT
        l.id
    FROM
        leads AS l
    WHERE
        l.email = :email
    LIMIT 1
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('email', $email);
        $stmt->execute();

        $result = $stmt->fetchAll();

        if (empty($result)) {
            return null;
        }

        return $result[0]['id'];
    }

    /*
     * @param string $email: the lead email
     *
     * @return int: the id of the lead
     */
    public function _addNewLead($email)
    {
        $q = <<<EOQ
INSERT INTO leads (is_published, points, date_modified, date_added, date_identified, email)
VALUES
    (1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :email)
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('email', $email);
        $stmt->execute();

        return $this->conn->lastInsertId();
    }

    /*
     * @param int $listId: the id of the SET list
     * @param int $leadId: the id of the Mautic lead
     */
    public function _addSetListLead($listId, $leadId)
    {
        $q = <<<EOQ
INSERT INTO setlists_leads (setlist_id, lead_id)
VALUES
    (:setlist_id, :lead_id)
ON DUPLICATE KEY UPDATE
    setlist_id=setlist_id,
    lead_id=lead_id
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('setlist_id', $listId);
        $stmt->bindValue('lead_id', $leadId);
        $stmt->execute();
    }
}
