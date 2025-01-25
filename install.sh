#!/bin/bash
# Author: Ruvenss G. Wilches
# Description: This script installs the autoupdate script in the crontab, the service, and the basic configuration
# Get the current directory
CURRENT_DIR=$(pwd)
# Define the cron job command
CRON_JOB="0 * * * * php $CURRENT_DIR/autoupdate.php"
# Check if the cron job already exists
CRON_EXISTS=$(crontab -l 2>/dev/null | grep -F "$CRON_JOB")
platform='unknown'
unamestr=$(uname)
sudo clear
if [[ "$unamestr" == 'Linux' ]]; then
    platform='linux'
    echo "$platform OS Detected. Installing MicroService for Debian like Distros"
    echo "⚙️ Installing Dependencies..."
    #sudo apt install -qq -y shc  > /dev/null 2>&1
    #sudo apt install -qq -y unzip > /dev/null 2>&1
    #sudo apt install -qq -y zip > /dev/null 2>&1
    #sudo apt install -qq -y dialog > /dev/null 2>&1
    if dialog --title 'Deployment directory' --backtitle "PMSRAPI" --yesno "Confirm deployment directory:\n\n$CURRENT_DIR" 7 60; then
        dialog --title 'Config' --infobox '✅ Let us start by setting the basic config file' 7 60
        sleep 3
        mms_name=$(dialog --backtitle "PMSRAPI " --title 'config.php' --inputbox 'Enter your microservice name' 7 60  --output-fd 1)
        mms_version=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice version' 7 60 --output-fd 1)
        mms_description=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice Description' 7 60  --output-fd 1)
        mms_author=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice main author name' 7 60  --output-fd 1)
        mms_author_email=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice Support email' 7 60  --output-fd 1)
        mms_author_website=$(dialog --title 'PMSRAPI' --inputbox 'Enter your microservice Support Website or Documentation' 7 60  --output-fd 1)
        mms_license=$(dialog --menu "License" 20 45 35 MIT "MIT" GPLV3 "GPL v3" APACHE "Apache" --output-fd 1)
        mms_last_updated=$(dialog --title 'PMSRAPI' --inputbox 'Last update date' 7 60  --output-fd 1)
        mms_github_repo=$(dialog --title 'PMSRAPI' --inputbox 'GitHub Repo' 7 60  --output-fd 1)
        mms_logserver=$(dialog --title 'PMSRAPI' --inputbox 'Logness Server URL' 7 60  --output-fd 1)
        mv config_sample.php config.php
        sed -i -e "s/mms_name/$mms_name/g" config.php
        sed -i -e "s/mms_version/$mms_version/g" config.php
        sed -i -e "s/mms_description/$mms_description/g" config.php
        sed -i -e "s/mms_author/$mms_author/g" config.php
        sed -i -e "s/mms_author_email/$mms_author_email/g" config.php
        sed -i -e "s/mms_license/$mms_license/g" config.php
        sed -i -e "s/mms_last_updated/$mms_last_updated/g" config.php
        sed -i -e "s/mms_github_repo/$mms_github_repo/g" config.php
        sed -i -e "s/mms_logserver/$mms_logserver/g" config.php
        chown www-data:www-data config.php
        if [ -z "$CRON_EXISTS" ]; then
            # Add the cron job
            # (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
            dialog --title 'CRONJOB' --infobox 'Auto Update of the framework has been added to your CRONJOBS' 7 60
            sleep 3
        else
            dialog --title 'CRONJOB' --infobox 'It seems you already installed before, and the autoupdate was already activated' 7 60
            sleep 3
        fi
    else
        clear
        echo "❌ Installation Cancelled $dialo_status"
        exit 1
    fi
fi