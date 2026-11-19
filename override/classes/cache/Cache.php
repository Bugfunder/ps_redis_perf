<?php

abstract class Cache extends CacheCore
{
    /**
     * Disable PrestaShop's internal SQL query cache tracking to stop the table-to-query map bottleneck.
     * Controlled via PS_REDIS_PERF_DISABLE_QUERY_CACHE (defaults to enabled / bypassed).
     */
    public function setQuery($query, $result)
    {
        if (
            !class_exists('Configuration', false)
            || !Configuration::configurationIsLoaded()
            || Configuration::getGlobalValue('PS_REDIS_PERF_DISABLE_QUERY_CACHE') !== '0'
        ) {
            return;
        }

        return parent::setQuery($query, $result);
    }
}
