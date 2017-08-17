<?php

/*
 * @package     Mauldin RabbitMQ
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinRabbitMQBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;

class DefaultController extends CommonController {

    public function indexAction($page = 1) {
        //setup http request
        $host = $this->container->getParameter("mautic.rabbitmq_host");
        $port = $this->container->getParameter("mautic.rabbitmq_webport");
        $username = $this->container->getParameter("mautic.rabbitmq_username");
        $password = $this->container->getParameter("mautic.rabbitmq_password");
        $columns = [
            "name",
            "auto_delete",
            "messages",
            "messages_details.rate",
            "messages_ready",
        ];
        $remote_url = 'http://' . $host . ":" . $port . '/api/queues?columns=' . implode(",", $columns);
        $opts = array(
            'http'=>array(
                'method'=>"GET",
                'header' => "Authorization: Basic " . base64_encode("$username:$password"),
            ));
        # run http request and parse answer
        $context = stream_context_create($opts);
        $file = file_get_contents($remote_url, false, $context);
        $data = json_decode($file, true);
        $queues = [];
        //proccess http response to build item list required by view
        foreach($data as $e){
            $rate = $this->getOrDefault($e, "messages_details.rate", 0);
            $total = $this->getOrDefault($e, "messages", 0);
            $queues[] = [
                "is_auto_delete" => $e["auto_delete"] ? "Yes" : "No",
                "rate" => $rate,
                "time_to_empty" => $this->getTimeToEmpty($total, $rate),
                "pname" => $this->prettifyName($e["name"]),
                "total" => $total,
            ];
        }

        usort($queues, function($a, $b){return strcmp($a["pname"], $b["pname"]);});

        //render view
        return $this->delegateView([
            'contentTemplate' => 'MauticMauldinRabbitMQBundle:RabbitMQStatus:index.html.php',
            'viewParameters' => [
                'items' => $queues,
            ]
        ]);
    }

    protected function getOrDefault($array, $key, $default){
        // deal with case of nested arrays
        if(strpos($key, ".") !== false){
            $keys = explode(".", $key);
            $target = $array;
            $q = count($keys);
            for($i = 0; $i < $q - 1; $i++){
                $target = $this->getOrDefault($target, $keys[$i], []);
            }
            return $this->getOrDefault($target, $keys[$q-1], $default);
        }
        if(array_key_exists($key, $array)){
            return $array[$key];
        }
        return $default;
    }

    protected function prettifyName($name) {
        /**
         * Turn "fOo.FOO-foO_foo,Foo-CSI" into "Foo Foo Foo Foo Foo CSI"
         */
        $name = preg_replace("/\s+/", " ", $name);
        $substrings = preg_split("/[., _-]/", $name);
        $prettified = [];
        $protected_nouns = ["CSI"];
        foreach($substrings as $s){
            if(in_array($s, $protected_nouns)){
                $prettified[] = $s;
            }else{
                $prettified[] = ucfirst(strtolower($s));
            }
        }

        if(in_array("Trigger", $prettified)){
            $base = implode(" ", array_slice($prettified, 0, count($prettified) - 1));
            $index = $prettified[count($prettified) - 1];
            switch($base){
                case "Trigger Negative":
                    return sprintf("Campaign %02d - Trigger Timeout", $index);
                case "Trigger Start":
                    return sprintf("Campaign %02d - Trigger Normal", $index);
                case "Trigger Scheduled":
                    return sprintf("Campaign %02d - Trigger Scheduled", $index);
                default:
                   break;
            }
        }

        return implode(" ", $prettified);

    }

    protected function getTimeToEmpty($total, $rate){
        if($rate >= 0 || $total == 0){
            return "-";
        }
        $s = - $total / $rate;
        $out = "";
        $h = $s / (60*60);
        if ($h > 0)
            $out = $out . sprintf("%2dh");
        $m = ($s / 60) % 60;
        if ($m > 0)
            $out = $out . sprintf("%2dm");
        return $out . sprintf("%2ds", $s % 60);
    }
}
