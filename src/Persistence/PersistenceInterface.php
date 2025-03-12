<?php

namespace Ody\SwooleRedis\Persistence;

use Ody\SwooleRedis\Storage\StringStorage;
use Ody\SwooleRedis\Storage\HashStorage;
use Ody\SwooleRedis\Storage\ListStorage;
use Ody\SwooleRedis\Storage\KeyExpiry;

/**
 * Interface for implementing persistence strategies
 */
interface PersistenceInterface
{
    /**
     * Save the current dataset to storage
     *
     * @return bool True if the save was successful
     */
    public function save(): bool;

    /**
     * Load data from storage
     *
     * @return bool True if the load was successful
     */
    public function load(): bool;

    /**
     * Set the storage repositories to persist
     *
     * @param StringStorage $stringStorage String storage repository
     * @param HashStorage $hashStorage Hash storage repository
     * @param ListStorage $listStorage List storage repository
     * @param KeyExpiry $keyExpiry Key expiry manager
     * @return void
     */
    public function setStorageRepositories(
        StringStorage $stringStorage,
        HashStorage $hashStorage,
        ListStorage $listStorage,
        KeyExpiry $keyExpiry
    ): void;
}