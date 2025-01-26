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
