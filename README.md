# PHP Micro Service REST API

Yes! Yet another REST API built in PHP.

<img src="https://github.com/ruvenss/pmsrapi/blob/main/documentation/pmsrapi.png?raw=true" style="width: 64px">

## Requierements

- Ubuntu Linux 24.04 or greather
- PHP 8.3 or greather
- PHP Extensions: mysql, mbstring, intl, xml, zip
- Bash
- unzip
- root access

### Basic installation

```bash
apt update
apt upgrade -y
apt install unzip
mkdir my_microservice
cd my_microservice
wget -q https://github.com/ruvenss/pmsrapi/archive/refs/tags/0.0.1.zip -O "pmsrapi.zip"
unzip -qq ./"pmsrapi.zip" && rm ./"pmsrapi.zip"
./install.sh
```
#### Setup

Follow the instructions with the wizzard Setup

<img src="https://github.com/ruvenss/pmsrapi/blob/main/documentation/screenshot_pmsrapi.jpg?raw=true" style="width: 100%">

Each step will allow you to auto-generate the config file and hidden manifest so you can deploy your own GIT Repo inside this code, without revealing any tokens, passwords or important information. The file containing this secrets will be stored in the parent directory of your project under the name of your project in this case `my_microservice.json`

You can edit this file and add your own project data as well.

The way to play along is simple. Do not modify the core files, they will be automatically updated on each release and you'll see the changes in your GIT.

For any issues please contact me. Made with love in Belgium
