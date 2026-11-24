<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// Preserve prepaid inventory, fulfillment records and encryption material.
wp_clear_scheduled_hook('yhlm_cleanup');
