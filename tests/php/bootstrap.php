<?php
// Unit-test bootstrap: loads plugin classes without booting WordPress.

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

foreach (glob(dirname(__DIR__, 2) . '/wp-content/mu-plugins/moneyfrog-core/src/*.php') ?: [] as $file) {
    require_once $file;
}
