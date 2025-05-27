# PHP Micro Service REST API

Yes! Yet another REST API built in PHP.

<img src="https://github.com/ruvenss/pmsrapi/blob/main/documentation/pmsrapi.png?raw=true" style="width: 64px">

## Requierements

- Ubuntu Linux 24.10 or greather
- PHP 8.3 or greather
- PHP Extensions: mysql, mbstring, intl, xml, zip, sqlite
- Bash
- unzip
- root access

### Basic installation

```bash
apt update
apt upgrade -y
apt install unzip
mkdir /home/my_microservice
cd /home/my_microservice
wget -q https://github.com/ruvenss/pmsrapi/archive/refs/tags/0.0.17.zip -O "pmsrapi.zip"
unzip -qq pmsrapi.zip && rm pmsrapi.zip
mv pmsrapi-0.0.17/* /home/my_microservice
./install.sh
```

#### Setup

Follow the instructions with the wizzard Setup

<img src="https://github.com/ruvenss/pmsrapi/blob/main/documentation/screenshot_pmsrapi.jpg?raw=true" style="width: 100%">

Each step will allow you to auto-generate the config file and hidden manifest so you can deploy your own GIT Repo inside this code, without revealing any tokens, passwords or important information. The file containing this secrets will be stored in the parent directory of your project under the name of your project in this case `my_microservice.json`

You can edit this file and add your own project data as well.

The way to play along is simple. Do not modify the core files, they will be automatically updated on each release and you'll see the changes in your GIT. A .gitignore file will be automatically deployed the first time you install the framework, allowing your repo to cohexist without touching the framework.

For any issues please contact me. Made with love in Belgium

## Updating

Once the framework is installed sucesfully using install.sh and you've chose to allow automatic updates, your framework will automatically update to the latest version published on GitHub

If you have chose manual updates, then you need to run in the same directory of your project `php update.php` in the terminal.

## Features

- Basic CRUD
- Advance CRUD
- Upload Files
- Download Files
- WebHooks
- Maps other microservices
- One Microservice per DataBase
- Extend your code in different files
- Events
- Customisable events
- Customisable Functions
- Works with NIZU Cloud
- Works with LightWeeb
- Works with OrderLemon
- Works with WordPress
- Available as Container (soon)
- Simple code.
- Compatible with nginx using proxy balancer and multiple instances
- Compatible with Apache 2.4x using reverse proxy
- PHP8.3 or greater
- Compatible with MariaDB and MySQL
- Compatible with MongoDB (soon)
- Compatible SQLite (soon)
