<?php

/**
 * Lightweight in-process key-value cache.
 * Avoids repeated expensive calls (e.g., getUserProfile) within a single request.
 */
function cache($key, $callback) {
    static $store = [];
    if (!isset($store[$key])) {
        $store[$key] = $callback();
    }
    return $store[$key];
}
