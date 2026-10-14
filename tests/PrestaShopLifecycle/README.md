# Genuine PrestaShop module lifecycle verification

Run from the module repository:

```sh
python3 tests/PrestaShopLifecycle/run.py --disposable
```

Requires Docker access, Python 3.12+ (safe tar extraction), the local
`prestashop-dev-prestashop:latest` image containing pristine PrestaShop 9.1.0,
`mariadb:11.4`, and committed historical module revision `0b8868b` (version 1.0.0).
For an isolated compatibility assessment, pass an immutable image reference with
`--image`, an immutable MariaDB reference with `--database-image`, and the matching
`--expected-version` (`9.1.0`, `9.1.5`, or `9.2.0`; default `9.1.0`). The application
image must contain the same pristine installer layout. The harness checks the
actual installed version and refuses a mismatch. These options do not change the
original upstream-defect acceptance expectations or isolation guards. Missing history or failed installation is a failure,
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

## P0-02D.1 retry diagnostics

Read-only observations now include structural verification and shop enablement.
After the known CLI failure the harness checks real same-version discovery in a
separate process, reruns the supported CLI and compares complete observed state.
It also directly retries the migration to confirm incompatible storage remains
rejected and unchanged. The known failing acceptance gate remains unchanged.
See the workspace [root-cause report](../../../../docs/development/module-upgrade-integrity-p0-02d1.md).

## P0-02D.2 independent verification acceptance

The existing lifecycle sequence now additionally invokes the standalone gate in
separate read-only containers, with read-only application mounts and a dedicated
SELECT/SHOW VIEW account. Test setup creates canonical definer metadata views to
make MariaDB trigger absence observable without giving the verifier TRIGGER/DDL
privileges. A capability view proves the definer still has global trigger visibility.
Verifier processes never create them. Fixtures/grants run only in owned storage.

All persistent table/view rows and DDL are hashed before/after each gate process.
Write, INSERT and CREATE probes on the reader must be denied. Gate evidence
includes actual JSON and process exit codes, success/failure of schema independent
of enablement, real active-shop enablement, malformed configuration, denied
credentials/metadata, disconnected database and missing trusted metadata views.
`verification_gate.status` is separate from the unchanged original acceptance
failure. See [command documentation](../../bin/README.md).

## P0-02D.3 explicit recovery acceptance

Additional isolated accounts have database SELECT/SHOW VIEW plus CREATE only on
the canonical registry, or native upgrade DML without CREATE. The latter reproduces
a genuine supported CLI false success with missing storage: version 1.1.0,
disabled module, no registry. The harness restores the original native connection
configuration before guarded observations and never rewrites registered versions.

Recovery runs in separate read-only containers/application mounts, with live
challenge-response confirmation. Dry-run, missing authorization, ambiguous storage,
canonical empty creation, repeated/compatible populated no-op and populated
incompatible refusal compare protected/complete persistent snapshots. Two pending
independently authorized recovery processes must produce exactly one creation.
The post-recovery verification CLI remains unchanged and still rejects disablement.
`recovery_cases`, `concurrent_recovery` and `recovery_acceptance` are separate from
`verification_gate` and the unchanged upstream P0-02D failure. See
[recovery documentation](../../bin/RECOVERY.md).
