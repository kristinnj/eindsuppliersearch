#!/usr/bin/env python3
"""Provision and verify genuine lifecycle behavior. Requires explicit disposable opt-in."""
import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tarfile
import tempfile
import threading
import time

MODULE = Path(__file__).resolve().parents[2]
HISTORICAL = "0b8868b"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--disposable", action="store_true", required=True)
    parser.add_argument("--image", default="prestashop-dev-prestashop:latest")
    args = parser.parse_args()
    run_id = "hlutiris-p002d-" + secrets.token_hex(6)
    directory = Path(tempfile.mkdtemp(prefix=run_id + "-"))
    os.chmod(directory, 0o700)
    egress = run_id + "-installer-egress"
    network, db, app = run_id + "-net", run_id + "-db", run_id + "-app"
    volumes = [run_id + "-database", run_id + "-application"]
    db_name = "hlutiris_lifecycle_test_" + run_id.rsplit("-", 1)[1]
    password, root_password = secrets.token_hex(24), secrets.token_hex(24)
    admin_password, token = secrets.token_hex(24), secrets.token_hex(24)
    secrets_to_redact = [password, root_password, admin_password, token]
    resources = []
    report = {"run": run_id, "historical_revision": HISTORICAL, "scenarios": []}
    assertions = 0
    worker_assertions = 0
    counts_lock = threading.Lock()

    def invoke(command, timeout=300):
        result = subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
        output = result.stdout
        for secret in secrets_to_redact:
            output = output.replace(secret, "[REDACTED]")
        if result.returncode:
            raise RuntimeError("Command failed (exit %d):\n%s" % (result.returncode, output[-10000:]))
        return output

    def docker(*arguments, **kwargs):
        return invoke(["docker", *arguments], **kwargs)

    def verify_topology():
        for name, expected_volume in [(db, volumes[0]), (app, volumes[1])]:
            data = json.loads(docker("inspect", name))[0]
            if data["Config"]["Labels"].get("hlutiris.lifecycle.run") != run_id:
                raise RuntimeError("Container ownership check failed")
            if set(data["NetworkSettings"]["Networks"]) != {network} or data["HostConfig"]["PortBindings"]:
                raise RuntimeError("Container network isolation check failed")
            mounts = data["Mounts"]
            owned = [m for m in mounts if m["Type"] == "volume"]
            if len(owned) != 1 or owned[0]["Name"] != expected_volume:
                raise RuntimeError("Container storage isolation check failed")
            if any(m["Type"] == "bind" and m["RW"] for m in mounts):
                raise RuntimeError("Writable host bind refused")
        for volume in volumes:
            if json.loads(docker("volume", "inspect", volume))[0]["Labels"].get("hlutiris.lifecycle.run") != run_id:
                raise RuntimeError("Volume ownership check failed")
        data = json.loads(docker("network", "inspect", network))[0]
        if not data["Internal"] or data["Labels"].get("hlutiris.lifecycle.run") != run_id:
            raise RuntimeError("Internal network ownership check failed")
        if set(data["Containers"]) != set(json.loads(docker("inspect", name))[0]["Id"] for name in [db, app]):
            raise RuntimeError("Unexpected network participant")

    def worker(action, *parameters):
        nonlocal worker_assertions
        verify_topology()
        output = docker("exec", app, "php", "/harness/worker.php", action, *parameters)
        result = json.loads(output)
        with counts_lock:
            worker_assertions += result.get("worker_checks", 0)
        return result

    def check(condition, message):
        nonlocal assertions
        assertions += 1
        if not condition:
            raise AssertionError(message)

    def record(name, evidence):
        report["scenarios"].append({"name": name, "evidence": evidence})
        print("PASS " + name, flush=True)

    def fresh_baseline():
        worker("reset")
        worker("stage", "historical")
        evidence = worker("install", "1.0.0")
        check(evidence["version"] == "1.0.0" and not evidence["registry_exists"], "Historical installation baseline failed")
        worker("stage", "current")
        return evidence

    try:
        archive = directory / "historical.tar"
        with archive.open("wb") as output:
            subprocess.run(["git", "-C", str(MODULE), "archive", HISTORICAL], stdout=output, check=True)
        historical = directory / "historical"
        historical.mkdir()
        with tarfile.open(archive) as source:
            source.extractall(historical, filter="data")
        check("'1.0.0'" in (historical / "eindsuppliersearch.php").read_text(), "Historical revision is not version 1.0.0")
        db_env = directory / "database.env"
        db_env.write_text(f"MARIADB_ROOT_PASSWORD={root_password}\nMARIADB_DATABASE={db_name}\nMARIADB_USER=lifecycle_test\nMARIADB_PASSWORD={password}\n")
        app_env = directory / "application.env"
        app_env.write_text(f"HLUTIRIS_LIFECYCLE_DISPOSABLE=YES\nHLUTIRIS_LIFECYCLE_RUN={run_id}\nHLUTIRIS_LIFECYCLE_TOKEN={token}\nHLUTIRIS_LIFECYCLE_DATABASE={db_name}\nHLUTIRIS_LIFECYCLE_HOST={db}\nHLUTIRIS_LIFECYCLE_USER=lifecycle_test\nHLUTIRIS_LIFECYCLE_PASSWORD={password}\nHLUTIRIS_LIFECYCLE_ADMIN_PASSWORD={admin_password}\nXDEBUG_MODE=off\n")
        for path in [db_env, app_env]:
            os.chmod(path, 0o600)
        label = "hlutiris.lifecycle.run=" + run_id
        docker("network", "create", "--internal", "--label", label, network)
        resources.append(("network", network))
        docker("network", "create", "--label", label, egress)
        resources.append(("network", egress))
        for volume in volumes:
            docker("volume", "create", "--label", label, volume)
            resources.append(("volume", volume))
        docker("run", "-d", "--name", db, "--hostname", db, "--label", label,
               "--network", network, "--env-file", str(db_env), "-v", volumes[0] + ":/var/lib/mysql", "mariadb:11.4")
        resources.append(("container", db))
        docker("run", "-d", "--name", app, "--hostname", app, "--label", label,
               "--network", network, "--env-file", str(app_env), "-v", volumes[1] + ":/var/www/html",
               "-v", str(MODULE) + ":/test-source:ro", "-v", str(historical) + ":/test-historical:ro",
               "-v", str(Path(__file__).resolve().parent) + ":/harness:ro",
               "--entrypoint", "sleep", args.image, "infinity")
        resources.append(("container", app))
        verify_topology()
        deadline = time.monotonic() + 60
        while True:
            result = subprocess.run(["docker", "exec", db, "healthcheck.sh", "--connect", "--innodb_initialized"], capture_output=True)
            if result.returncode == 0:
                break
            if time.monotonic() > deadline:
                raise RuntimeError("Disposable database readiness timed out")
            time.sleep(1)
        print("Isolated resources verified; installing pristine PrestaShop.", flush=True)
        docker("exec", app, "sh", "-c", "cp -a /tmp/data-ps/prestashop/. /var/www/html/; chown -R www-data:www-data /var/www/html")
        docker("network", "connect", egress, app)
        try:
            output = docker("exec", app, "php", "/harness/install.php", timeout=300)
        finally:
            docker("network", "disconnect", egress, app)
        verify_topology()
        docker("network", "rm", egress)
        resources.remove(("network", egress))
        (directory / "installer.log").write_text(output)
        baseline = worker("baseline")
        check(baseline["prestashop"] == "9.1.0", "Expected real PrestaShop 9.1.0")
        report["baseline"] = baseline
        record("genuine PrestaShop bootstrap and isolated fixture baseline", baseline)

        worker("stage", "current")
        fresh = worker("install", "1.1.0")
        check(fresh["success"] and fresh["version"] == "1.1.0", "Fresh install failed")
        check(fresh["registry_exists"] and fresh["registry_count"] == 0 and fresh["schema_valid"], "Fresh registry not empty/correct")
        record("fresh 1.1.0 installation and empty registry", fresh)

        repeat = worker("repeat")
        check(repeat["unchanged"] and repeat["schema_valid"], "Repeated migration changed fixtures")
        record("migration retries preserve exact bytes and records", repeat)
        uninstall = worker("uninstall")
        check(uninstall["success"] and uninstall["retained"] and uninstall["cleanup_valid"], "Uninstall/retention failed")
        record("native uninstall retains registry and performs expected cleanup", uninstall)
        reinstall = worker("reinstall")
        check(reinstall["success"] and reinstall["retained"] and reinstall["version"] == "1.1.0", "Retained-registry reinstall failed")
        record("native reinstall accepts retained registry", reinstall)

        historical_evidence = fresh_baseline()
        record("genuine historical 1.0.0 installation", historical_evidence)
        upgrade = worker("upgrade", "success")
        check(upgrade["success"] and upgrade["version"] == "1.1.0", "Native runner/version persistence failed")
        check(upgrade["available"] == 1 and upgrade["executed"] == 1 and upgrade["upgraded_to"] == "1.1.0", "Upgrade script was not discovered/executed")
        check(upgrade["schema_valid"] and upgrade["configuration_unchanged"] and upgrade["module_records_unchanged"] and upgrade["enabled"], "Upgrade changed configuration/schema/enablement")
        record("native 1.0.0 to 1.1.0 upgrade discovery and persistence", upgrade)
        direct = worker("direct")
        check(direct["success"] and direct["unchanged"], "Direct upgrade function idempotence failed")
        record("upgrade function independently verified", direct)
        interruption = worker("interrupted")
        check(interruption["success"] and interruption["unchanged"], "Interrupted DDL recovery failed")
        record("recovery after creation before verification", interruption)
        before_concurrent = worker("state")
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            concurrent_results = list(pool.map(lambda _: worker("migrate"), range(2)))
        after_concurrent = worker("state")
        check(all(result["success"] for result in concurrent_results), "Concurrent migration failed")
        check(before_concurrent == after_concurrent, "Concurrent migration changed records/schema")
        record("concurrent migration processes preserve schema and records", {"workers": 2, "unchanged": True})

        for defect in ["index", "column", "check", "engine"]:
            fresh_baseline()
            fixture = worker("incompatible", defect)
            failure = worker("upgrade", "failure")
            check(not failure["success"] and failure["version"] == "1.0.0", "Failed migration silently advanced version")
            check(failure["version_fail"] == "1.1.0" and failure["executed"] == 0, "Failure runner status incorrect")
            check(not failure["enabled"] and failure["registry_unchanged"] and failure["errors"], "Failure did not disable/preserve/report")
            check(failure["configuration_unchanged"] and failure["module_records_unchanged"], "Failure changed existing configuration")
            record("native upgrade failure preserves incompatible " + defect, failure)

        # Exercise the supported high-level CLI separately from the legacy runner.
        fresh_baseline()
        cli_before = worker("observe")
        verify_topology()
        cli_success_output = docker("exec", app, "php", "/var/www/html/bin/console", "prestashop:module",
                                    "upgrade", "eindsuppliersearch", "--env=prod", "--no-debug")
        cli_after = worker("observe")
        check(cli_after["version"] == "1.1.0" and cli_after["registry"]["exists"] and cli_after["enabled"],
              "Supported CLI success path failed")
        check(cli_before["configuration"] == cli_after["configuration"] and cli_before["module_records"] == cli_after["module_records"], "CLI success changed module configuration")
        record("supported native CLI successful historical upgrade", cli_after)

        fresh_baseline()
        worker("incompatible", "index")
        cli_before = worker("observe")
        verify_topology()
        cli_failure_output = docker("exec", app, "php", "/var/www/html/bin/console", "prestashop:module",
                                    "upgrade", "eindsuppliersearch", "--env=prod", "--no-debug")
        cli_after = worker("observe")
        check(cli_before["registry"] == cli_after["registry"] and not cli_after["enabled"],
              "Supported CLI failed to preserve/disable incompatible registry")
        check(cli_before["configuration"] == cli_after["configuration"] and cli_before["module_records"] == cli_after["module_records"], "CLI failure changed module configuration")
        core_failure = cli_after["version"] != "1.0.0"
        check("failed" in cli_failure_output.lower() or "error" in cli_failure_output.lower(),
              "Supported CLI did not report migration failure")
        report["acceptance_failures"] = ["Native CLI advances registered version on failed migration"] if core_failure else []
        report["core_cli_failure"] = {"version_advanced_on_failure": core_failure, "exit_code": 0,
                                      "output": cli_failure_output[-3000:], "evidence": cli_after}
        record("supported native CLI failure behavior observed", report["core_cli_failure"])

        report["status"] = "incomplete_core_failure" if core_failure else "passed"
        report["assertions"] = assertions
        report["worker_assertions"] = worker_assertions
        if core_failure:
            print("INCOMPLETE: native CLI advanced registered version despite migration failure; core was preserved.", flush=True)
        print(f"OBSERVED {len(report['scenarios'])} lifecycle scenarios, {assertions} orchestrator assertions, {worker_assertions} worker assertions.", flush=True)
    except Exception as error:
        report["status"] = "failed"
        report["error"] = str(error)
        report["assertions"] = assertions
        print("FAIL " + str(error), flush=True)
        raise
    finally:
        for kind, name in reversed(resources):
            try:
                inspected = json.loads(docker(*( ["inspect", name] if kind == "container" else [kind, "inspect", name])))[0]
                labels = inspected["Config"]["Labels"] if kind == "container" else inspected["Labels"]
                if labels.get("hlutiris.lifecycle.run") != run_id:
                    raise RuntimeError("Cleanup ownership check failed")
                docker(*( ["rm", "-f", name] if kind == "container" else [kind, "rm", name]))
            except Exception as error:
                report.setdefault("cleanup_errors", []).append(str(error))
        for path in [directory / "database.env", directory / "application.env", directory / "historical.tar"]:
            path.unlink(missing_ok=True)
        if (directory / "historical").exists():
            shutil.rmtree(directory / "historical")
        (directory / "report.json").write_text(json.dumps(report, indent=2) + "\n")
        print("Sanitized evidence: " + str(directory / "report.json"), flush=True)
        if report.get("cleanup_errors"):
            raise RuntimeError("Some task-owned resources could not be cleaned up; see report")

    if report["status"] != "passed":
        raise SystemExit(2)


if __name__ == "__main__":
    main()
