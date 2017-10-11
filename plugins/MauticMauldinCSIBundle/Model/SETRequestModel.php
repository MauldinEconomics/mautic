<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Model;

use MauticPlugin\MauticMauldinCSIBundle\Exception\SETAPIException;
use GGC\SETClient\Client;
use GGC\SETClient\Configuration;

class SETRequestModel
{
    private $entityCode;
    private $host;
    private $privateKey;
    private $userGuid;

    private $client;

    public function __construct($entityCode, $host, $privateKey, $userGuid)
    {
        $this->entityCode = $entityCode;
        $this->host       = $host;
        $this->privateKey = $privateKey;
        $this->userGuid   = $userGuid;

        $settings = [
            'host'        => $host,
            'entity_code' => $entityCode,
            'user_guid'   => $userGuid,
            'private_key' => $privateKey,
        ];

        $config = new Configuration($settings);
        $this->client = new Client($config);
    }

    /*
     * Helper function for centralizing request handling.
     *
     * @throws SETAPIException
     */
    private function apiCall($endpoint, $subject = null, $data = [], $query = [], $method = null)
    {
        try {
            $call = $this->client->call($endpoint, $subject, $data, $query, $method);
        } catch (\Exception $e) {
            throw new SETAPIException('Request to SET failed: ' . $e->getMessage());
        }

        $result = $call->getResult();
        $resultData = $result->getData();

        if (!$result->isSuccessful()) {
            throw new SETAPIException('Request to SET failed', 0, $resultData);
        }

        // Handles the special case
        if ($endpoint === 'listbuild_cache') {
            $response = $call->getResponse()->getBody();
            if ($response instanceof \GuzzleHttp\Psr7\Stream) {
                return $this->handleListCache($response);
            }
        }

        return $resultData;
    }

    /*
     * @param \GuzzleHttp\Psr7\Stream $response
     *
     * Returns a list of email addresses
     * @return array(string)
     */
    private function handleListCache(\GuzzleHttp\Psr7\Stream $response)
    {
        $response->rewind(0);
        $lines = explode("\n", $response->getContents());

        $emails = [];
        foreach ($lines as $line) {
            if (filter_var($line, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $line;
            }
        }

        return [
            'email_list' => $emails,
        ];
    }

    /*
     * Makes a request to the SET api and returns a dictionary of SET lists:
     * [
     *   $listId => $listName,
     *   ...
     * ]
     *
     * Since it is called from the UI, it fails safelly in case of API errors.
     *
     * @return array
     */
    public function getSetLists()
    {
        try {
            $data = $this->apiCall('list');
        } catch (SETAPIException $e) {
            return [];
        }

        $result = [];
        foreach ($data as $d) {
            $result[$d['id']] = $d['name'];
        }

        return $result;
    }

    /*
     * @param int $listId: The id of the SET list
     *
     * Returns the name of the SET list
     * @return string
     */
    public function requestCacheUpdate($listId)
    {
        $this->apiCall('listbuild_queue', null, [], ['id' => $listId]);

        $result = $this->apiCall('list', null, [], ['id' => $listId]);

        return $result['name'];
    }

    /*
     * A newer cache is available if the time it finished bulding is more
     * recent then the time the currently used cache was built.
     *
     * @param int $listId: The id of the SET list
     * @param datetime $lastUpdate: the datetime when the last cache build finished
     *
     * Returns null if a newer cache is not available;
     * otherwise, returns the $timeFinished of the new cache.
     * @return maybe(datetime)
     */
    public function isNewerCacheAvailable($listId, $lastUpdate)
    {
        $result = $this->apiCall('listbuild_status', null, [], ['id' => $listId]);

        $timeFinished = strtotime($result['time_finished']);

        if (null !== $timeFinished && $timeFinished > $lastUpdate) {
            return $timeFinished;
        }

        return null;
    }

    /*
     * @param int $listId: The id of the SET list
     *
     * Returns an array of email addresses
     * @return array
     */
    public function downloadCache($listId)
    {
        $result = $this->apiCall('listbuild_cache', null, [], ['id' => $listId]);

        if (!isset($result['email_list'])) {
            return null;
        }

        return $result['email_list'];
    }
}
