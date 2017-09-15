<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Model;

use MauticPlugin\MauticMauldinCSIBundle\Helper\XmlParserHelper;
use MauticPlugin\MauticMauldinCSIBundle\Exception\CSIAPIException;

class CSIRequestModel
{
    const CSI_API_V2 = 'api/v2';

    private $username;
    private $password;
    private $apiKey;
    private $entity;
    private $host;

    public function __construct($username, $password, $apiKey, $entity, $host)
    {
        $this->username = $username;
        $this->password = $password;
        $this->apiKey   = $apiKey;
        $this->entity   = $entity;
        $this->host     = $host;
    }

    /*
     * @return string
     */
    public function buildUrl(array $urlParts)
    {
        return implode('/', array_merge([$this->host, $this->entity, CSIRequestModel::CSI_API_V2], $urlParts));
    }

    public function get($url, $data = false)
    {
        $curl = curl_init();

        $api_id = uniqid('ApiID_', true);

        $timestamp = time();

        $hash = base64_encode(hash_hmac('sha512', $timestamp, $this->apiKey, true));

        // Add request data
        if ($data) {
            $url = sprintf('%s?%s', $url, http_build_query($data));
        }

        // Header Configuration
        $auth = $this->username.':'.$this->password;
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $auth);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Accept: application/xml',
            'User-Agent: Mautic Jobs Client Library',
            'X-Site-Referer-Url: '.((empty($_SERVER['HTTP_REFERER'])) ? 'Unknown' : $_SERVER['HTTP_REFERER']),
            'X-Site-Server-Url: '.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'],
            'X-ApiVersion: '.'v2',
            "X-ApiID: {$api_id}",
            "X-Auth: {$hash}",
            "X-Entity: {$this->entity}",
            "X-Stamp: {$timestamp}",
        ]);

        // Make GET Call
        $result   = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        switch ($httpCode) {
            case 200:
                return $result;
                break;
            case 404:
                throw new CSIAPIException("Can't reach url: ".$url, 404);
                break;
            default:
                throw new CSIAPIException('HTTP Request failed: ', $httpCode);
                break;
        }
    }

    /*
     * @return array
     *
     * @throws CSIAPIException
     */
    public function simpleGet(array $urlParts, $data = false)
    {
        $url = $this->buildUrl($urlParts);
        $response = $this->get($url, $data);
        $result   = XmlParserHelper::arrayFromXml($response);

        if ($result['response']['success']) {
            return $result;
        } else {
            $result['request'] = ['url' => $url, 'data' => $data];
            $result['time']    = new \DateTime();
            throw new CSIAPIException('CSI API Exception: request to "'.$url.'" failed', 200, $result);
        }
    }
}
