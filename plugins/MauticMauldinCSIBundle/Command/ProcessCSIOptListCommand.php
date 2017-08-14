<?php

/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use MauticPlugin\MauticMauldinCSIBundle\Exception\CSIAPIException;
use MauticPlugin\MauticMauldinCSIBundle\Helper\XmlParserHelper;
use MauticPlugin\MauticMauldinEmailScalabilityBundle\MessageQueue\ChannelHelper;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to process the e-mail queue.
 */
class ProcessCSIOptListCommand extends ModeratedCommand
{
    private $username;
    private $password;
    private $api_key;
    private $entity;
    private $host;
    const CSI_ENDPOINT = '/api/v2/listmanager/';
    const CSI_OPT_OUT  = self::CSI_ENDPOINT.'optOut/live';
    const CSI_OPT_IN   = self::CSI_ENDPOINT.'optIn/live';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('mauldin:csi:lists:update')
            ->setDescription('Processes CSI Queue actions and make API Calls')
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command is used to process the application's e-mail queue

<info>php %command.full_name%</info>
EOT
            );
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        $this->username = $container->getParameter('mautic.csiapi_username');
        $this->password = $container->getParameter('mautic.csiapi_password');
        $this->api_key  = $container->getParameter('mautic.csiapi_key');
        $this->entity   = $container->getParameter('mautic.csiapi_entity_code');
        $this->host     = $container->getParameter('mautic.csiapi_host');
        $dispatcher     = $container->get('event_dispatcher');

        /** @var ChannelHelper $channelHelper */
        $channelHelper = $container->get('mauldin.scalability.message_queue.channel_helper');
        $queue         = $channelHelper->declareQueue('csi_list');

        $callback = function ($msg) {
            $this->process($msg);
        };

        // TODO: improve message retry functionality for now its is just going to retry forever until the message is sent
        // We can implement TTL expiring or dead lettered messages as specified in rabbitmq documentation
        $queue->consume($callback);

        // Timeout in 10 seconds  and give up on $max_timeout
        $max_timeout     = 10;
        $timeout_counter = 0;

        while ($queue->hasChannelCallbacks() && ($timeout_counter < $max_timeout)) {
            try {
                $queue->wait(0.2);
                $timeout_counter = 0;
            } catch (AMQPTimeoutException $e) {
                $output->writeln('Wait timeout counter '.$timeout_counter);
                $timeout_counter = $timeout_counter + 1;
            }
        }

        return 0;
    }

    private function get($url, $data = false)
    {
        $curl = curl_init();

        $api_id = uniqid('ApiID_', true);

        $timestamp = time();

        $hash = base64_encode(hash_hmac('sha512', $timestamp, $this->api_key, true));

        // build request url
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

        // build request url
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

        //Make GET Call
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

    /**
     * @param string $email
     * @param array  $list
     *
     * @return
     */
    public function optIn($email, $list)
    {
        $url      = $this->host.'/'.$this->entity.self::CSI_OPT_IN;
        $data     = ['email' => $email, 'code' => $list];
        $response = $this->get($url, $data);
        $result   = XmlParserHelper::arrayFromXml($response);
        if (!$result['response']['success']) {
            $result['request'] = ['url' => $url, 'data' => $data];
            $result['time']    = new \DateTime();
            throw new CSIAPIException('CSI API Exception: request to "'.$url.'" failed', 200, $result);
        }
    }

    /**
     * @param string $email
     * @param array  $list
     *
     * @return
     */
    public function optOut($email, $list)
    {
        $url      = $this->host.'/'.$this->entity.self::CSI_OPT_OUT;
        $data     = ['email' => $email, 'code' => $list];
        $response = $this->get($url, $data);
        $result   = XmlParserHelper::arrayFromXml($response);
        if (!$result['response']['success']) {
            $result['request'] = ['url' => $url, 'data' => $data];
            $result['time']    = new \DateTime();
            throw new CSIAPIException('CSI API Exception: request to "'.$url.'" failed', 200, $result);
        }
    }

    private function process($msg)
    {
        try {
            $message = unserialize($msg->body);
            var_dump($message);
            if (isset($message['add'])) {
                $this->optIn($message['add']['lead'], $message['add']['list']);
            }
            if (isset($message['remove'])) {
                $this->optOut($message['remove']['lead'], $message['remove']['list']);
            }
            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
        } catch (CSIAPIException $e) {
            switch ($e->getCode()) {
                case 200:
                    // Log api errors to a file in json format
                    $file = fopen('app/logs/csiapi-opt.log', 'a');
                    fwrite($file, json_encode($e->getErrorData())."\n");
                    fclose($file);
                    // Remove api return errors from queue
                    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
                    break;
                default:
                    // Don't acknowledge the message in the other case
                    // So the message is not removed from the queue
            }
        }
    }
}
