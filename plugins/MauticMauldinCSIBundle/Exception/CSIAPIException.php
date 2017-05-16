<?php
/*
 * @package     Mauldin CSI
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinCSIBundle\Exception;

use Throwable;

class CSIAPIException extends \Exception
{
    private $errorData;
    public function __construct($message = '', $code = 0, $errorData = null, Throwable $previous = null)
    {
        $this->errorData = $errorData;
        parent::__construct($message, $code, $previous);
    }

    public function getErrorData()
    {
        return $this->errorData;
    }
}
