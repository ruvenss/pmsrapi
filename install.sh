#!/bin/bash
# Author: Ruvenss G. Wilches
# Description: This script installs the autoupdate script in the crontab, the service, and the basic configuration
# Get the current and parent directory
# Check if the current user is root
if [ "$EUID" -eq 0 ]; then
    echo "Starting installation..."
else
    echo "You are NOT running as root. Please run as root."
    exit 1
fi
CURRENT_DIR=$(pwd)
PARENT_DIR=$(dirname "$CURRENT_DIR")
SAMPLE_CONFIG="${CURRENT_DIR}/v1/sample_config.json"
# Define the cron job command
CRON_JOB="0 * * * * cd $CURRENT_DIR;php autoupdate.php"
# Check if the cron job already exists
CRON_EXISTS=$(crontab -l 2>/dev/null | grep -F "$CRON_JOB")
platform='unknown'
unamestr=$(uname)
sudo clear
if [[ "$unamestr" == 'Linux' ]]; then
    platform='linux'
    echo "$platform OS Detected. Installing MicroService for Debian like Distros"
    echo "⚙️ Installing Dependencies..."
    sudo apt install -qq -y shc  > /dev/null 2>&1
    sudo apt install -qq -y unzip > /dev/null 2>&1
    sudo apt install -qq -y zip > /dev/null 2>&1
    sudo apt install -qq -y dialog > /dev/null 2>&1
    sudo rm -f .vscode
    sudo rm -f .git
    sudo rm -f .gitignore
    sudo rm -f README.md
    sudo rm -f LICENSE
    if dialog --title 'Deployment directory' --backtitle "PMSRAPI" --yesno "Confirm deployment directory:\n$CURRENT_DIR\n\nConfirm parent directory:\n$PARENT_DIR" 17 60; then
        dialog --title 'Config' --infobox '✅ Let us start by setting the basic config file' 7 60
        sleep 3
        mms_name=$(dialog --backtitle "PMSRAPI" --title 'config.php' --inputbox 'Enter your microservice name' 7 60  --output-fd 1)
        mms_version=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice version' 7 60 --output-fd 1)
        mms_description=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice Description' 7 60  --output-fd 1)
        mms_author=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice main author name' 7 60  --output-fd 1)
        mms_author_email=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice Support email' 7 60  --output-fd 1)
        mms_author_website=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice Support Website or Documentation' 7 60  --output-fd 1)
        mms_license=$(dialog --menu "License" 20 45 35 MIT "MIT" GPLV3 "GPL v3" APACHE "Apache" --output-fd 1)
        mms_last_updated=$(dialog --title 'PMSRAPI' --inputbox 'Last update date' 7 60  --output-fd 1)
        mms_github_repo=$(dialog --title 'PMSRAPI' --inputbox 'GitHub Repo' 7 60  --output-fd 1)
        mms_logserver=$(dialog --title 'PMSRAPI' --inputbox 'Logness Server URL' 7 60  --output-fd 1)
        mms_logservertoken=$(dialog --title 'PMSRAPI' --inputbox 'Logness Token' 7 60  --output-fd 1)
        mms_config_path="${PARENT_DIR}/${mms_name}.json"
        CONFIG_DATA="<?php
// Define the database connection and private tokens out of your source code
define(\"config_path\", \"${mms_config_path}\");
// Define your Microservice details
define(\"ms_name\", \"${mms_name}\");
define(\"ms_version\", \"${mms_version}\");
define(\"ms_description\", \"${mms_description}\");
define(\"ms_author\", \"${mms_author}\");
define(\"ms_author_email\", \"${mms_author_email}\");
define(\"ms_author_website\", \"${mms_author_website}\");
define(\"ms_license\", \"${mms_license}\");
define(\"ms_documentation\", \"${mms_documentation}\");
define(\"ms_last_updated\", \"${mms_last_updated}\");
define(\"ms_github_repo\", \"${mms_github_repo}\");
// Define the responses for the RESTful API, you can add more responses if you need
define(\"ms_restful_responses\", [\"200\" => \"OK\", \"201\" => \"Created\", \"204\" => \"No Content\", \"400\" => \"Bad Request\", \"401\" => \"Unauthorized\", \"403\" => \"Forbidden\", \"404\" => \"Not Found\", \"405\" => \"Method Not Allowed\", \"409\" => \"Conflict\", \"410\" => \"Gone\", \"500\" => \"Internal Server Error\"]);
define(\"ms_http_headers\", [\"Content-Type\" => \"application/json\", \"Access-Control-Allow-Origin\" => \"*\", \"Access-Control-Allow-Methods\" => \"GET, POST, PUT, DELETE, OPTIONS\", \"Access-Control-Allow-Headers\" => \"Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With\"]);
define(\"ms_logserver\", \"${mms_logserver}\");
"
        echo "$CONFIG_DATA" > $CURRENT_DIR/v1/config.php
        httpport=$(dialog --backtitle "PMSRAPI" --title 'MicroService HTTP' --inputbox 'Enter the port where this service will live' 7 60 "8001"  --output-fd 1)
        httphost=$(dialog --backtitle "PMSRAPI" --title 'MicroService HTTP' --inputbox 'Enter the IP where this service will live' 7 60 "0.0.0.0"  --output-fd 1)
        environtment=$(dialog --menu "PMSRAPI Environment" 20 45 35 dev "Development" test "Test" stage "Staging" prod "Production" --output-fd 1)
        loglevel=$(dialog --menu "PMSRAPI Debug log level" 20 45 35 info "Information and Error" error "Only errors" none "No logs" prod "Production" --output-fd 1)
        if dialog --title 'Database' --backtitle "PMSRAPI" --yesno "Is your service connected to MySQL or MariaDB?" 7 60; then
            dbhost=$(dialog --backtitle "PMSRAPI" --title 'DataBase Setup' --inputbox 'Enter your DB Host' 7 60 "localost"  --output-fd 1)
            dbname=$(dialog --backtitle "PMSRAPI" --title 'DataBase Setup' --inputbox 'Enter your DB Name' 7 60 --output-fd 1)
            dbuser=$(dialog --backtitle "PMSRAPI" --title 'DataBase Setup' --inputbox 'Enter your DB User' 7 60 "root" --output-fd 1)
            dbpass=$(dialog --backtitle "PMSRAPI" --title 'DataBase Setup' --passwordbox 'Enter your DB Password' 7 60 --output-fd 1)
            dbport=$(dialog --backtitle "PMSRAPI" --title 'DataBase Setup' --inputbox 'Enter your DB Port' 7 60 "3306" --output-fd 1)
            JSON_DATA="{
    \"db\": {
        \"host\": \"${dbhost}\",
        \"port\": ${dbport},
        \"name\": \"${dbname}\",
        \"username\": \"${dbuser}\",
        \"password\": \"${dbpass}\"
    },
    \"http\": {
        \"port\": ${httpport},
        \"host\": \"${httphost}\"
    },
    \"allowed_actions\": [
        \"read\",
        \"update\",
        \"create\",
        \"delete\"
    ],
    \"allowed_functions\":{
        \"PUT\":[],
        \"POST\":[],
        \"GET\":[],
        \"DELETE\":[]  
    },
    \"ms_logserver_token\":\"${mms_logservertoken}\",
    \"ms_logserver_url\":\"${mms_logserver}\",
    \"env\": \"${environtment}\",
    \"local_log\": {
        \"path\": \"/var/log/${mms_name}.log\",
        \"level\": \"${loglevel}\"
    }
}"
        else
            JSON_DATA="{
    \"http\": {
        \"port\": ${httpport},
        \"host\": \"${httphost}\"
    },
    \"allowed_actions\": [
        \"read\",
        \"update\",
        \"create\",
        \"delete\"
    ],
    \"allowed_functions\":{
        \"PUT\":[],
        \"POST\":[],
        \"GET\":[],
        \"DELETE\":[]  
    },
    \"ms_logserver_token\":\"${mms_logservertoken}\",
    \"ms_logserver_url\":\"${mms_logserver}\",
    \"env\": \"${environtment}\",
    \"local_log\": {
        \"path\": \"/var/log/${mms_name}.log\",
        \"level\": \"${loglevel}\"
    }
}"
        fi
        echo "$JSON_DATA" > $mms_config_path
        dialog --title 'Config' --infobox "✅ Configuration moved to\n\n$mms_config_path\nYou can add your own payloads in this file" 7 60
        sleep 3
        if [ -z "$CRON_EXISTS" ]; then
            # Add the cron job
            (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
            dialog --title 'CRONJOB' --infobox 'Auto Update of the framework has been added to your CRONJOBS' 7 60
            sleep 3
        else
            dialog --title 'CRONJOB' --infobox 'It seems you already installed before, and the autoupdate was already activated' 7 60
            sleep 3
        fi
        clear
        echo "✅ Configuration Completed"
        echo "__________________________"
        echo "🚀 Deploying service type cd v1;php deploy.php"
        cd v1; php deploy.php
        echo "🚀 Service Deployed"
        echo "if you wish to expose this service to the internet, you can use Apache, NGINX reverse proxy or a service like ngrok"
        echo "Your service is running at http://${httphost}:${httpport} try it now"
        echo "by using curl http://${httphost}:${httpport}"
        echo ""
        chown -R www-data:www-data $CURRENT_DIR
    else
        clear
        echo "❌ Installation Cancelled $dialo_status"
        exit 1
    fi
fi