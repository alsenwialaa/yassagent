<?php

if (!function_exists('dbDelta')) {
    function dbDelta($statement)
    {
        global $wpdb;
        return $wpdb->applyDbDelta((string) $statement);
    }
}
