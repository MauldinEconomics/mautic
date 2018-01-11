<?php

/*
 * @package   Mauldin Email Scalability
 * @copyright   Copyright(c) 2017 by GGC PUBLISHING, LLC
 * @author      Brick Abode
 */

namespace MauticPlugin\MauticMauldinEmailScalabilityBundle\Transport;

/**
 * Memory Transaction Interface.
 */
interface MemoryTransactionInterface
{
    /**
     * @return bool
     */
    public function begin();

    /**
     * @return bool
     */
    public function commit();

    /**
     * @return bool
     */
    public function rollback();
}
