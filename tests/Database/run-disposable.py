#!/usr/bin/env python3
"""Run the existing guarded MariaDB suite in newly owned disposable resources."""
import argparse
import json
from pathlib import Path
import secrets
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--disposable', action='store_true', required=True)
    parser.add_argument('--image', default='prestashop-dev-prestashop:latest')
    parser.add_argument('--database-image', default='mariadb:11.4')
    args = parser.parse_args()
    suffix = secrets.token_hex(6)
    run = 'hlutiris-gate-db-' + suffix
    database = 'hlutiris_offer_binding_test_' + suffix
    network, volume, server = run + '-net', run + '-data', run + '-server'
    password, root_password = secrets.token_hex(24), secrets.token_hex(24)
    label = 'hlutiris.verification.run=' + run
    resources = []

    def docker(*args, input=None, check=True):
        result = subprocess.run(['docker', *args], input=input, text=True, capture_output=True)
        if check and result.returncode:
            raise RuntimeError('Disposable database orchestration failed')
        return result

    with tempfile.TemporaryDirectory(prefix=run) as directory:
        env = Path(directory) / 'database.env'
        env.write_text(f'MARIADB_ROOT_PASSWORD={root_password}\nMARIADB_DATABASE={database}\nMARIADB_USER=guarded_test\nMARIADB_PASSWORD={password}\n')
        env.chmod(0o600)
        testenv = Path(directory) / 'tests.env'
        testenv.write_text(f'EIND_TEST_DISPOSABLE=YES\nEIND_TEST_DB_NAME={database}\nEIND_TEST_DISPOSABLE_DATABASE={database}\nEIND_TEST_APPLICATION_DATABASES=eind_dev\nEIND_TEST_DB_HOST={server}\nEIND_TEST_DB_PORT=3306\nEIND_TEST_DB_USER=guarded_test\nEIND_TEST_DB_PASSWORD={password}\nXDEBUG_MODE=off\n')
        testenv.chmod(0o600)
        try:
            docker('network', 'create', '--internal', '--label', label, network)
            resources.append(('network', network))
            docker('volume', 'create', '--label', label, volume)
            resources.append(('volume', volume))
            docker('run', '-d', '--name', server, '--hostname', server, '--label', label,
                   '--network', network, '--env-file', str(env), '-v', volume + ':/var/lib/mysql', args.database_image)
            resources.append(('container', server))
            data = json.loads(docker('inspect', server).stdout)[0]
            if data['HostConfig']['PortBindings'] or set(data['NetworkSettings']['Networks']) != {network} \
                    or len(data['Mounts']) != 1 or data['Mounts'][0]['Name'] != volume:
                raise RuntimeError('Disposable server topology refused')
            deadline = time.monotonic() + 60
            while docker('exec', server, 'healthcheck.sh', '--connect', '--innodb_initialized', check=False).returncode:
                if time.monotonic() > deadline:
                    raise RuntimeError('Disposable readiness timed out')
                time.sleep(1)
            # Guards run before any suite mutation; no native installation is used.
            result = docker('run', '--rm', '--read-only', '--network', network, '--label', label,
                            '--env-file', str(testenv), '-v', str(Path(__file__).resolve().parents[2]) + ':/workspace/modules/eindsuppliersearch:ro',
                            '-w', '/workspace/modules/eindsuppliersearch', '--entrypoint', 'php', args.image,
                            'vendor-dev/bin/phpunit', '-c', 'phpunit.database.xml.dist', '--do-not-cache-result', check=False)
            output = result.stdout + result.stderr
            for secret in [password, root_password]:
                output = output.replace(secret, '[REDACTED]')
            print(output, end='')
            exit_code = result.returncode
        finally:
            for kind, name in reversed(resources):
                data = json.loads(docker(*(['inspect', name] if kind == 'container' else [kind, 'inspect', name])).stdout)[0]
                labels = data['Config']['Labels'] if kind == 'container' else data['Labels']
                if labels.get('hlutiris.verification.run') != run:
                    raise RuntimeError('Cleanup ownership refused')
                docker(*(['rm', '-f', name] if kind == 'container' else [kind, 'rm', name]))
    raise SystemExit(exit_code)


if __name__ == '__main__':
    main()
