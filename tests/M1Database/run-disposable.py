#!/usr/bin/env python3
"""Provision only newly owned MariaDB resources for M1 tests; no existing volumes."""
import argparse
import hashlib
import json
from pathlib import Path
import secrets
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--disposable', action='store_true', required=True)
    parser.add_argument('--image', default='prestashop/prestashop@sha256:c2139b3d862efa7babe995364ffa8c6c5189ec4308f69d0d252a11c0508c4adf')
    parser.add_argument('--database-image', default='mariadb@sha256:67873d30a17f6a9c331f06363b2fa15f38abca415529966d67c84f87f82439fe')
    args = parser.parse_args()
    suffix = secrets.token_hex(6)
    run = 'hlutiris-m1-' + suffix
    database = 'hlutiris_offer_binding_test_m1_' + suffix
    network, volume, server = run+'-net', run+'-data', run+'-server'
    passwords = {key: secrets.token_hex(24) for key in ['root', 'test', 'reader', 'insert', 'migration', 'meta', 'broad', 'grant', 'role']}
    token = secrets.token_hex(24)
    label = 'hlutiris.m1.run='+run
    resources = []
    directory = Path(tempfile.mkdtemp(prefix=run+'-'))
    module = Path(__file__).resolve().parents[2]

    def docker(*command, input=None, check=True):
        result = subprocess.run(['docker', *command], input=input, text=True, capture_output=True)
        if check and result.returncode:
            print(sanitize(result.stdout+result.stderr))
            raise RuntimeError('Owned M1 orchestration failed')
        return result

    def sanitize(output):
        for secret in [*passwords.values(), token]:
            output = output.replace(secret, '[REDACTED]')
        return output

    def root_sql(sql):
        return docker('exec', '-i', server, 'sh', '-c',
                      'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" --batch --skip-column-names '+database, input=sql)

    def php(*command, check=True):
        return docker('run', '--rm', '--read-only', '--tmpfs', '/tmp', '--network', network, '--label', label,
                      '--env-file', str(directory/'tests.env'), '-v', str(module)+':/workspace/modules/eindsuppliersearch:ro',
                      '-v', str(directory/'ownership.json')+':/ownership.json:ro', '-w', '/workspace/modules/eindsuppliersearch',
                      '--entrypoint', 'php', args.image, *command, check=check)

    try:
        env=directory/'database.env'
        env.write_text(f'MARIADB_ROOT_PASSWORD={passwords["root"]}\nMARIADB_DATABASE={database}\nMARIADB_USER=guarded_test\nMARIADB_PASSWORD={passwords["test"]}\n')
        env.chmod(0o600)
        docker('network','create','--internal','--label',label,network); resources.append(('network',network))
        docker('volume','create','--label',label,volume); resources.append(('volume',volume))
        docker('run','-d','--name',server,'--hostname',server,'--label',label,'--network',network,
               '--env-file',str(env),'-v',volume+':/var/lib/mysql',args.database_image)
        resources.append(('container',server))
        data=json.loads(docker('inspect',server).stdout)[0]
        v=json.loads(docker('volume','inspect',volume).stdout)[0]
        n=json.loads(docker('network','inspect',network).stdout)[0]
        if data['Config']['Labels'].get('hlutiris.m1.run')!=run or v['Labels'].get('hlutiris.m1.run')!=run \
                or n['Labels'].get('hlutiris.m1.run')!=run or not n['Internal'] \
                or data['HostConfig']['PortBindings'] or set(data['NetworkSettings']['Networks'])!={network} \
                or len(data['Mounts'])!=1 or data['Mounts'][0].get('Name')!=volume or data['Mounts'][0]['Type']!='volume':
            raise RuntimeError('M1 topology/ownership refused')
        deadline=time.monotonic()+60
        while docker('exec',server,'healthcheck.sh','--connect','--innodb_initialized',check=False).returncode:
            if time.monotonic()>deadline: raise RuntimeError('M1 readiness unavailable')
            time.sleep(1)
        actual=root_sql('SELECT DATABASE(),@@hostname;').stdout.strip().split('\t')
        if actual != [database,server]: raise RuntimeError('Owned server SQL identity refused')
        manifest={'run':run,'server':server,'database':database,'volume':volume,'network':network,'topology_verified':True,
                  'image':args.image,'database_image':args.database_image}
        (directory/'ownership.json').write_text(json.dumps(manifest,indent=2)); (directory/'ownership.json').chmod(0o600)
        root_sql('CREATE TABLE m1_test_authorization (run_id VARCHAR(64),token_sha256 CHAR(64));\n'
                 f"INSERT INTO m1_test_authorization VALUES ('{run}','{hashlib.sha256(token.encode()).hexdigest()}');")
        testenv=directory/'tests.env'
        testenv.write_text(f'EIND_TEST_DISPOSABLE=YES\nEIND_TEST_DB_NAME={database}\nEIND_TEST_DISPOSABLE_DATABASE={database}\n'
                          f'EIND_TEST_APPLICATION_DATABASES=eind_dev\nEIND_TEST_DB_HOST={server}\nEIND_TEST_DB_PORT=3306\n'
                          f'EIND_TEST_DB_USER=guarded_test\nEIND_TEST_DB_PASSWORD={passwords["test"]}\n'
                          f'EIND_M1_OWNERSHIP=/ownership.json\nEIND_M1_RUN_TOKEN={token}\nXDEBUG_MODE=off\n'
                          + ''.join(f'EIND_M1_{key.upper()}_PASSWORD={passwords[key]}\n' for key in ['reader','insert','migration','broad','grant','role']))
        testenv.chmod(0o600)
        seed=php('tests/M1Database/seed.php'); print(sanitize(seed.stdout+seed.stderr),end='')
        sql=''
        for key in ['meta','reader','insert','migration','broad','grant','role']:
            sql+=f"CREATE USER 'm1{key}'@'%' IDENTIFIED BY '{passwords[key]}';\n"
        sql+="GRANT TRIGGER ON *.* TO 'm1meta'@'%';\n"
        sql+=f"CREATE DEFINER='m1meta'@'%' SQL SECURITY DEFINER VIEW test_eind_upgrade_trigger_metadata AS SELECT TRIGGER_SCHEMA,EVENT_OBJECT_TABLE,TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{database}';\n"
        sql+="CREATE DEFINER='m1meta'@'%' SQL SECURITY DEFINER VIEW test_eind_upgrade_metadata_capability AS SELECT GRANTEE,PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES;\n"
        for key in ['reader','migration']:
            sql+=f"GRANT SELECT, SHOW VIEW ON `{database}`.* TO 'm1{key}'@'%';\n"
        for name in ['eind_observation','eind_quote']:
            sql+=f"GRANT SELECT,INSERT ON `{database}`.`test_{name}` TO 'm1insert'@'%';\n"
            sql+=f"GRANT CREATE ON `{database}`.`test_{name}` TO 'm1migration'@'%';\n"
        # Deliberately overprivileged disposable accounts must be refused by production preflight.
        sql+=f"GRANT SELECT,CREATE,SHOW VIEW ON `{database}`.* TO 'm1broad'@'%';\n"
        sql+=f"GRANT SELECT,SHOW VIEW ON `{database}`.* TO 'm1grant'@'%' WITH GRANT OPTION;\n"
        sql+="CREATE ROLE m1_unexpected_role;\nGRANT m1_unexpected_role TO 'm1role'@'%';\n"
        sql+=f"GRANT SELECT,SHOW VIEW ON `{database}`.* TO 'm1role'@'%';\n"
        root_sql(sql)
        result=php('vendor-dev/bin/phpunit','-c','phpunit.m1.xml.dist','--do-not-cache-result',check=False)
        output=sanitize(result.stdout+result.stderr); print(output,end=''); (directory/'tests.log').write_text(output)
        (directory/'result.json').write_text(json.dumps({'exit_code':result.returncode,'topology':manifest},indent=2))
        exit_code=result.returncode
    finally:
        for kind,name in reversed(resources):
            data=json.loads(docker(*(['inspect',name] if kind=='container' else [kind,'inspect',name])).stdout)[0]
            labels=data['Config']['Labels'] if kind=='container' else data['Labels']
            if labels.get('hlutiris.m1.run')!=run: raise RuntimeError('M1 cleanup ownership refused')
            docker(*(['rm','-f',name] if kind=='container' else [kind,'rm',name]))
        for name in ['database.env','tests.env']:
            (directory/name).unlink(missing_ok=True)
        print('Owned M1 resources cleaned; sanitized artifacts: '+str(directory))
    raise SystemExit(exit_code)


if __name__=='__main__':
    main()
