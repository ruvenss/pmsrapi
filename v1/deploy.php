<?php

/**
 * Deploy the Microservice to the server
 * This script will create a systemd service for the Microservice
 * @category MicroService
 * @package  MicroService_Restful_API
 * @version  1.0.0
 * @since    1.0.0
 * @link   https://github.com/ruvenss/pmsrapi
 * IF YOU ADD YOUR CODE HERE IT WILL BE OVERWRITTEN ON THE NEXT UPDATE
 */

if (!defined('STDIN')) {
    header("HTTP/1.0 404 Not Found", true, 404);
    die();
}
if (PHP_OS_FAMILY !== 'Linux') {
    echo "🔴 This script only works on Ubuntu 22, and >24\n";
    die();
}
define("project_path", dirname(getcwd(), 1));

echo "🪂 Deploying Microservice project in " . project_path . "\n";
if (!file_exists(project_path . '/v1/config.php')) {
    echo "🟠 Please complete your config file first at " . project_path . "/v1/config.php\n";
    echo "  Then run this script again\n";
    die();
}
include_once project_path . '/v1/config.php';
if (!file_exists(config_path)) {
    echo "🟠 The Microservice Secret Configuration file" . config_path . " is missing\n";
    echo "  Please save this file out of the http always. We suggest this path: " . config_path . "\n";
    echo "  Create the file and then run this script again\n";
    die();
}
echo "🚦 Checking if the Microservice for " . PHP_OS_FAMILY . " systemd service exists\n";
define("ms_secrets", json_decode(file_get_contents(config_path), true));
if (!file_exists('/etc/systemd/system/' . ms_name . '.service')) {
    echo "🟠 " . ms_name . ".service is missing\n";
    $ini = '[Unit]
Description=' . ms_description . '
After=network.target

[Service]
Environment=PHP_CLI_SERVER_WORKERS=' . WORKER_COUNT . '
ExecStart=/usr/bin/php -S ' . ms_secrets['http']['host'] . ':' . ms_secrets['http']['port'] . ' -t ' . project_path . '
Restart=always
User=root
Group=root
WorkingDirectory=' . project_path . '

[Install]
WantedBy=multi-user.target';
    file_put_contents('/etc/systemd/system/' . ms_name . '.service', $ini);
    echo "🟢 " . ms_name . ".service has been created\n🚦 Reloading daemons";
    exec("systemctl daemon-reload");
    echo "🚦 starting the service:\n";
    exec("systemctl start " . ms_name . ".service");
    echo "🚦 enabling the service to start at boot:\n";
    exec("systemctl enable " . ms_name . ".service");
} else {
    echo "🟢 " . ms_name . ".service already exists\n";
}
echo "🪂 Deploying Every Second Cron Job Execution at " . project_path . "/v1/cron/seconds.sh\n";
if (!file_exists(project_path . '/v1/cron/seconds.sh')) {
    echo "🟠 Please create the seconds.sh file first at " . project_path . "/v1/cron/seconds.sh\n";
    echo "  Then run this script again\n";
    die();
}
$seconds_service_name = ms_name . "_seconds";
if (!file_exists('/etc/systemd/system/' . $seconds_service_name . '.service')) {
    echo "🟠 " . $seconds_service_name . ".service is missing\n";
    $ini = '[Unit]
Description=' . ms_description . ' Every Second Cron Job Execution
After=network.target

[Service]
ExecStart=cd ' . project_path . '/v1/cron && ./seconds.sh
Restart=always
User=root
Group=root
WorkingDirectory=' . project_path . '/v1/cron

[Install]
WantedBy=multi-user.target';
    file_put_contents('/etc/systemd/system/' . $seconds_service_name . '.service', $ini);
    echo "🟢 " . $seconds_service_name . ".service has been created\n🚦 Reloading daemons";
    exec("systemctl daemon-reload");
    echo "🚦 starting the service:\n";
    exec("systemctl start " . $seconds_service_name . ".service");
    echo "🚦 enabling the service to start at boot:\n";
    exec("systemctl enable " . $seconds_service_name . ".service");
} else {
    echo "🟢 " . $seconds_service_name . ".service already exists\n";
}
