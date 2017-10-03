<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Model;

use \Doctrine\DBAL\Connection;

/*
 * The Email Send Log keeps a counter, for each (job, email) pair, of how
 * many emails this job has sent and when the last send happened.
 */
class EmailSendLogModel
{
    /*
     * Connection to database
     * @var Connection
     */
    private $conn;

    /*
     * Maximum value of $count until log is commited to database
     * @var int
     */
    private $limit;

    /*
     * Id of the job
     * @var int
     */
    private $jobId;

    /*
     * Id of the email sent previously
     * @var int
     */
    private $prevId = null;

    /*
     * How many email have been sent on this run.
     * @var int
     */
    private $count = 0;

    /*
     * Constructor
     */
    public function __construct(Connection $conn, $limit)
    {
        $this->conn = $conn;
        $this->limit = $limit;
    }

    /*
     * jobId setter
     */
    public function setJobId($id)
    {
        $this->jobId = (int)$id;
    }

    /*
     * Log an email send event.
     *
     * This actually keeps track of how many emails have been sent and only
     * commits after a while to avoid wasting time with database updates.
     *
     * @param int $id Id of the entity of the email just sent.
     */
    public function logEmailSend($id)
    {
        if ($this->shouldCommit($id)) {
            $this->commit();
        }

        $this->count += 1;
        $this->prevId = $id;
    }

    /*
     * The email send log should also be updated at the end of the email send
     * command execution.
     */
    public function logEmailSendEnd()
    {
        $this->commit();
    }

    /*
     * Commits the log update to the database and resets the send count.
     */
    private function commit()
    {
        if ($this->count > 0) {
            echo("job: $this->jobId, sent: $this->count, id: $this->prevId" . PHP_EOL);

            $this->_commit();

            $this->count = 0;
        }
    }

    /*
     * A log commit should happen if
     *  "the email changes"
     *      OR
     *  "a number of sends happen"
     *
     * Note that a commit should also happen at the end of the command
     * execution (see the 'logEmailSendEnd' function).
     *
     * @param int $id Id of the current email
     */
    private function shouldCommit($id)
    {
        return $id !== $this->prevId || $this->count === $this->limit;
    }

    /*
     * This is the actual implementation of the log commit. It upserts a row
     * in the log table. Each (job, email) pair has its own row.
     *
     * The "Send Example" feature results in the email id being NULL.
     * In this case, this function does nothing.
     */
    private function _commit() {
        if (null === $this->prevId) {
            return;
        }

        $q = <<<EOQ
INSERT INTO
        ba_email_send_log (job_id, email_id, send_count)
    VALUES
        (:job_id, :email_id, :send_count)
    ON DUPLICATE KEY UPDATE
        send_count = send_count + :send_count
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->bindValue('job_id', $this->jobId);
        $stmt->bindValue('email_id', $this->prevId);
        $stmt->bindValue('send_count', $this->count);
        $stmt->execute();
    }

    /*
     *
     */
    public function getEmailSendLogsSummary() {
        $q = <<<EOQ
SELECT
    e.id AS email_id,
    e.name AS email_name,
    l.send_count,
    l.last_send_date
FROM
    (SELECT
            email_id,
            SUM(send_count) as send_count,
            MAX(last_send_date) as last_send_date
        FROM
            mautic.ba_email_send_log
        GROUP BY email_id
    ) AS l
        JOIN
    mautic.emails AS e ON e.id = l.email_id
ORDER BY last_send_date DESC
EOQ;

        $stmt = $this->conn->prepare($q);
        $stmt->execute();
        $results = $stmt->fetchAll();

        return $results;
    }
}
