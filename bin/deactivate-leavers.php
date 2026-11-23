<?php
/**
 * Switch employees whose leaving date has passed to inactive and remove their self-service access.
 *
 *   php bin/deactivate-leavers.php
 *
 * Optional: run it daily from cron, e.g.  5 0 * * *  php /path/to/bin/deactivate-leavers.php
 * It is not required: sign-in and open portal sessions already refuse leavers by date, and the HR employee
 * list runs the same sweep once a day.
 */
require __DIR__ . '/_common.php';

mysqli_report(MYSQLI_REPORT_OFF);
$hp = \App\Support\BackupPlan::hostPort(DB_SERVER);
$c = @new mysqli($hp['host'], DB_USERNAME, DB_PASSWORD, DB_NAME, $hp['port']);
if ($c->connect_error) { nv_fail('Cannot connect to the database: ' . $c->connect_error); }
$n = \App\Services\LeaverService::sweepAll($c);
nv_say($n === 1 ? '1 employee deactivated.' : "$n employees deactivated.");
