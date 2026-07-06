#!/usr/bin/env bash
# DEV/TEST ONLY. Writes the test secret config, waits for the backends, then
# either serves v2 (default) or runs the test suite ("test").
set -euo pipefail

# v2/config.php resolves secrets_path to dirname(v2, 2)/weather.json => /opt/weather.json
cp /opt/pmsrapi/test-env/weather.test.json /opt/weather.json

wait_for() { # host port name
  echo "⏳ waiting for $3 ($1:$2)…"
  for _ in $(seq 1 60); do
    if php -r "\$c=@fsockopen('$1',$2,\$e,\$s,1); exit(\$c?0:1);"; then
      echo "✅ $3 reachable"; return 0
    fi
    sleep 1
  done
  echo "❌ timed out waiting for $3"; return 1
}

wait_for db 3306 MariaDB
wait_for redis 6379 Redis

case "${1:-serve}" in
  serve)
    echo "🚀 serving v2 at http://0.0.0.0:8080/v2 (Ctrl-C to stop)"
    exec php -S 0.0.0.0:8080 /opt/pmsrapi/v2/server.php
    ;;
  test)
    echo "🧪 starting server in background for the test run…"
    php -S 0.0.0.0:8080 /opt/pmsrapi/v2/server.php >/tmp/server.log 2>&1 &
    SRV=$!
    for _ in $(seq 1 40); do
      if php -r "exit(@file_get_contents('http://127.0.0.1:8080/v2/_debug')!==false?0:1);"; then break; fi
      sleep 0.5
    done
    set +e
    php /opt/pmsrapi/test-env/tests/run.php
    CODE=$?
    set -e
    kill "$SRV" 2>/dev/null || true
    exit "$CODE"
    ;;
  *)
    exec "$@"
    ;;
esac
