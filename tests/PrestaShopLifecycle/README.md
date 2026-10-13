# Genuine PrestaShop module lifecycle verification

Run from the module repository:

```sh
python3 tests/PrestaShopLifecycle/run.py --disposable
```

Requires Docker access, Python 3.12+ (safe tar extraction), the local
`prestashop-dev-prestashop:latest` image containing pristine PrestaShop 9.1.0,
`mariadb:11.4`, and committed historical module revision `0b8868b` (version 1.0.0).
Override the application image with `--image` only when it contains the same
pristine installer layout. The harness checks the actual installed version and
refuses a different release. Missing history or failed installation is a failure,
not permission to edit registered versions or claim an upgrade passed.

This suite is a Python orchestrator with separate PHP processes running genuine
core. It is deliberately excluded from ordinary PHPUnit discovery. It does not
load PHPUnit's parser dependencies into PrestaShop. No core substitutes are used
by these workers. Ordinary guard unit tests remain under
`tests/Infrastructure/Database/LifecycleGuardTest.php`.

## Isolation and authorization

`--disposable` is mandatory. Each run creates uniquely named/labeled containers,
an internal network, and separate application/database Docker volumes. It uses
new credentials, a database named `hlutiris_lifecycle_test_<run suffix>`, and a
server hostname matching the run. No host ports are published. Source and
historical archive mounts are read-only. Module code is copied into the owned
application volume; the development installation, database, theme, Compose file,
and Docker volumes are never reused.

The native installer is copied from `/tmp/data-ps/prestashop` inside the image.
Only the application is temporarily connected to a separate installer-egress
network to obtain native installation assets, including translations. That
network is detached and removed before module lifecycle tests. No supplier API
calls are made. Lifecycle tests have only the internal network.

Before each worker command the orchestrator checks labels, exact volume identities,
absence of writable host binds and published ports, and exact internal-network
participants. PHP independently verifies explicit authorization, actual DATABASE(),
@@hostname, CURRENT_USER(), database-restricted grants, an unprefixed run-token
marker, and exact installed database parameters. Installer mutation requires an
empty database. Subsequent mutation requires the original protected native data
snapshot to match. The marker is named `p002d_test_authorization` so it cannot match
the installer's `life_` SQL LIKE prefix check.

The original disposable database suite's guard is unchanged by P0-02D. It rejects
native application tables and therefore cannot be used to authorize a full native
installation; the lifecycle guard is intentionally separate and never provides a
bypass to that suite.

## Scenarios and evidence

The harness exercises the real module `install()`/`uninstall()` APIs and
`Module::initUpgradeModule()` / `Module::runUpgradeModule()` with AdminKernel and
native context bootstrapped. Upgrade discovery is asserted before execution;
the discovered filename/version and function inclusion are verified separately
from a direct upgrade-function call. Historical source is exported with
`git archive`, installed genuinely, then replaced only inside owned test storage.
Registered versions are never manually updated.

Coverage includes fresh 1.1.0 installation, genuine historical 1.0.0 installation
and native upgrade, structural verification, empty registry, exact-byte fixtures,
repeated migration, two concurrent migration processes, a simulated interruption
at the real canonical DDL boundary, uninstall cleanup, retained-record reinstall,
and native upgrade rejection of four incompatible schemas. Failure fixtures
modify only the disposable registry. Production CHECKs are not weakened.

Every applicable worker compares complete rows for native product, supplier,
stock, cart, order, customer, specific-price, address and guest tables with the
pristine installer baseline. Product/supplier references, prices and associations
are included through their entire rows. Legitimate module registration, hook,
configuration, log and cache changes are kept separate. Upgrade scenarios also
compare module configuration. Fixture identities/targets remain synthetic and
are never persisted as real supplier/product identities.

Native session SQL mode is recorded before/after every applicable action.
The shared schema entry point temporarily enables strict mode when core's
connection has cleared it and restores the original mode even on failure.
Generic schema installation still rejects non-strict mode.

A private temporary directory retains sanitized `report.json` and `installer.log`.
The report includes scenario evidence and exact assertion counts. On both success
and failure, cleanup verifies ownership and removes only this run's containers,
volumes and networks. Temporary credentials and historical export are removed.
Unexpected cleanup failures are reported as failures; no prune or broad cleanup
is used. A process killed before Python's finally block may leave task-owned
resources; inspect their exact run labels before explicitly removing them.

## Limits

This verifies one genuine PrestaShop 9.1.0 installation using installer demo
fixtures, not real customer/catalog data or a production deployment. Interruption
is simulated immediately after canonical creation; it is not a killed server.
Concurrent migration processes exercise the actual advisory lock, without a
promise about exact contention timing. Schema-permission denial and full Back
Office UI interaction are not covered. No creation/adoption/lifecycle writer or
runtime lookup integration is approved or exercised.

## Current acceptance result on PrestaShop 9.1.0

The legacy native runner passes installation/upgrade/version-retention scenarios.
The supported CLI also passes successful upgrade, but on incompatible registry
failure it displays errors, disables the module, advances registered version to
1.1.0 and exits 0. The harness therefore records `incomplete_core_failure` and
exits 2 **after owned-resource cleanup**. This is a detected core behavior, not a
silently skipped test. P0-02D acceptance remains incomplete. Core is not modified.
The report distinguishes scenario observations from the failed acceptance gate.
