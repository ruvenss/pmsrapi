#!/bin/bash
# Author: Ruvenss G. Wilches
# Description: This script installs the autoupdate script in the crontab, the service, and the basic configuration
# Get the current and parent directory
CURRENT_DIR=$(pwd)
PARENT_DIR=$(dirname "$CURRENT_DIR")
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
        mv $CURRENT_DIR/v1/config_sample.php $CURRENT_DIR/v1/config.php
        mms_config_path=$PARENT_DIR/$mms_name.json
        sed -i -e "s/mms_config_path/$mms_config_path/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_name/$mms_name/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_version/$mms_version/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_description/$mms_description/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_author/$mms_author/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_author_email/$mms_author_email/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_license/$mms_license/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_last_updated/$mms_last_updated/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_github_repo/$mms_github_repo/g" $CURRENT_DIR/v1/config.php
        sed -i -e "s/mms_logserver/$mms_logserver/g" $CURRENT_DIR/v1/config.php
        chown -R www-data:www-data $CURRENT_DIR
        mv $CURRENT_DIR/v1/sample_config.json $PARENT_DIR/$mms_config_path
        dialog --title 'Config' --infobox "✅ Configuration moved to\n\n$PARENT_DIR/$mms_config_path\nYou can add your own payloads in this file" 7 60
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
        echo "✅ Installation Completed, please remove the install.sh file, type:\n#~ rm install.sh\n\n"
        echo "🚀 To start the service: type cd v1;php deploy.php"
    else
        clear
        echo "❌ Installation Cancelled $dialo_status"
        exit 1
    fi
fi