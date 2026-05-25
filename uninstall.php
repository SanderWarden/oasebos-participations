<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
// Data is intentionally retained by default. Remove manually if Stichting Oasebos confirms data deletion.
delete_option('oasebos_participations_version');
