# Independent module upgrade verification (P0-02D.2)

This standalone CLI independently reads installed state. It does not execute
PrestaShop, the module constructor, Composer, installation, migration, upgrade,
repair, recovery or enablement. It runs while the module is disabled. It has no
storefront caller or active deployment integration.

```sh
EIND_VERIFY_CONFIG=/private/verification.json \
  php modules/eindsuppliersearch/bin/verify-module-upgrade.php --json
```

Run with the module in the installed `modules/eindsuppliersearch` layout. The
command always emits one JSON object to stdout, including without `--json`.
Only `--json` is accepted; module identity/schema/version cannot be overridden by
arguments. PHP needs pdo_mysql, SimpleXML and tokenizer. Source version and name
are read as PHP literals, without executing the file; XML must agree. Installed
`app/config/parameters.php` is read as text without execution. Unsupported dynamic
or noncanonical parameter syntax fails closed. Native default/empty/null ports
mean 3306. Target database, host, port and prefix must match installed parameters;
`SELECT DATABASE()` and `@@hostname` must match the explicit intended target.

## Private configuration

Create outside version control with restricted filesystem permissions. Do not
use the ordinary application account. Example placeholders:

```json
{
  "host": "isolated-db",
  "port": 3306,
  "database": "intended_database",
  "prefix": "installed_prefix_",
  "user": "verification_reader",
  "password": "<private reader password>",
  "server_hostname": "<verified MariaDB @@hostname>",
  "metadata_definer": "<trusted metadata account>@<host>"
}
```

The environment variable contains only a configuration path. Connection secrets,
PDO messages, SQL exceptions, account grants, customer/order data and registry row
contents never appear in normal diagnostics. No credentials are auto-discovered.
Native parameters supply target consistency checks, not application credentials.
Operator configuration and installed source/configuration are trusted inputs.

## Read-only privileges and metadata prerequisite

The reader must have exactly `SELECT, SHOW VIEW` on the intended database, plus
ordinary global USAGE, without grant option, extra grants or roles. SHOW VIEW is
read-only metadata permission. Full database SELECT is required to distinguish
absent tables from metadata hidden by partial permissions. The verifier uses
SELECT statements and one `SHOW GRANTS FOR CURRENT_USER` metadata read; no SET,
transactions, locks, DML, DDL, caches, sessions or log files are issued/written.
The existing schema verifier receives a mutation callback that always throws.

**MariaDB 11.4 hides trigger rows from a SELECT-only account.** A direct empty
information_schema.TRIGGERS result cannot attest trigger absence. The verifier
therefore requires two pre-existing administrator-managed views:

- `<prefix>eind_upgrade_trigger_metadata`: SQL SECURITY DEFINER, exactly
  `SELECT TRIGGER_SCHEMA, EVENT_OBJECT_TABLE, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '<intended database>'`.
- `<prefix>eind_upgrade_metadata_capability`: SQL SECURITY DEFINER, exactly
  `SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES`.

Both definers must equal `metadata_definer`. The definer must retain an explicit
global TRIGGER privilege so its metadata scope covers every registry trigger.
The capability view independently proves that privilege is present at verification
time. The trigger view must not be updatable. Canonical definitions are validated
through information_schema.VIEWS, allowing only harmless native formatting,
qualification and projection aliases. A missing, altered, inaccessible or wrongly
owned view, missing definer or lost visibility returns exit 2. The schema reader
redirects only its trigger metadata SELECT through this view; canonical columns,
indexes, CHECKs, engine and foreign-key verification remain shared unchanged.

The verifier never creates these views/accounts or grants privileges. They were
provisioned **only in disposable tests** in this milestone. Future non-test
provisioning requires separate authorization; no active database has them installed
by this task. Restrict access to the administrator-owned metadata views and audit
their definer. This is a metadata prerequisite, not schema recovery.

## Checks and deployment policy

`UpgradeVerification` coordinates independently injected registration/schema
readers. `PdoUpgradeReader` supplies parameterized, uncached reads; `VerificationSource`
handles explicit target/source metadata. It verifies:

- Exactly one canonical module registration and expected source version.
- Global active flag plus native module_shop associations, their shop existence,
  device bitmask and contradictory/orphan/duplicate state.
- **Every active, non-deleted shop is required**, with enable_device=7 (all three
  native device bits). There is no CLI shop filter or disabled-module bypass.
  Inactive/deleted shops are excluded from required enablement. No required shops
  fails. Existing associations must still be structurally consistent.
- Registry exists and passes `OfferBindingSchema::verify()`: InnoDB, exact columns,
  binary identity/digest storage, nullability, indexes/unique targets, CHECKs
  including reference length 1–4096, hash, states/provenance, absence of foreign
  keys/triggers. Unexpected schemas are preserved and rejected.
- Current verification session has check_constraint_checks=1 and unique_checks=1.
  This does not attest other sessions' enforcement or audit historical row data.

Strict all-shop/all-device enablement is the chosen verification policy; it is
stricter than simply observing the native global active flag. A legitimate shop-
restricted deployment will fail this gate and needs a separately reviewed policy.

## JSON and exit contract

Contract version is `1`. JSON reports `status` PASS/FAIL and `exit_code`; collected
state includes expected/registered version, module_enabled, required_shops,
registry.exists/schema_valid and check objects (name, PASS/FAIL/ERROR, stable code).
Unknown checks never count as success. Operational failures may omit uncollected
fields or leave them null. Independently detected inconsistencies remain visible
when another check encounters an operational failure. Exit 2 takes precedence.

| Exit | Classification | Meaning |
|---|---|---|
| 0 | PASS | Every required check completed successfully |
| 1 | Integrity FAIL | Missing module/registry, wrong version, source disagreement, disabled shop/module, contradictory registration or incompatible schema |
| 2 | Operational/configuration FAIL | Invalid/missing config/bootstrap, database unavailable/access failure, unknown metadata/permissions, or unexpected exception |

Stable failure codes include MODULE_NOT_REGISTERED, CONTRADICTORY_REGISTRATION,
VERSION_MISMATCH, SOURCE_METADATA_MISMATCH, REQUIRED_SHOP_DISABLED,
REGISTRY_MISSING, INCOMPATIBLE_SCHEMA, READ_FAILURE, CONFIGURATION_FAILURE,
DATABASE_ACCESS_FAILURE and BOOTSTRAP_FAILURE. Error text is never used as a
pipeline success criterion. READ_FAILURE is an ERROR check with exit 2.

Example abbreviated false-success verdict:

```json
{
  "contract_version": 1,
  "status": "FAIL",
  "exit_code": 1,
  "module": "eindsuppliersearch",
  "expected_version": "1.1.0",
  "registered_version": "1.1.0",
  "module_enabled": false,
  "registry": {"exists": true, "schema_valid": false},
  "checks": [
    {"name": "module_enabled", "status": "FAIL", "code": "REQUIRED_SHOP_DISABLED"},
    {"name": "registry_schema", "status": "FAIL", "code": "INCOMPATIBLE_SCHEMA"}
  ]
}
```

## Testing and operational boundary

```sh
php vendor-dev/bin/phpunit --do-not-cache-result
python3 tests/Database/run-disposable.py --disposable
python3 tests/PrestaShopLifecycle/run.py --disposable
```

Run these from the module root. The database runner provisions a fresh MariaDB
server/database and uses the **unchanged** disposable database guard. Source is
mounted read-only. The lifecycle harness uses genuine native installation and
separate SELECT/SHOW VIEW accounts; write/DDL probes must be denied. Every gate
process runs in a read-only container with the application volume mounted read-only,
and complete persistent table/view rows and DDL are hashed before and after.
Pure tests inspect query types and prohibit PDO exec/transactions; guarded DB
tests verify schema queries are SELECT. Fixtures/grants/views are isolated test
setup, never verifier actions.

The lifecycle report has a separate `verification_gate.status` and case JSON/
exit evidence. Its original P0-02D gate still exits 2 for the known upstream
version advancement / CLI zero-exit defects. Gate acceptance does not resolve them.

Future deployment automation must call this command independently after an
otherwise authorized upgrade and block release progression on **either nonzero
exit**. The gate attests observed postconditions, not proof the upgrade script
ran, and does not freeze a deployment or schema against concurrent modification.
Deployments must serialize changes separately. No workflow is installed here.
No automatic repair, migration, version-history rewriting, record adoption,
binding creation or recovery is performed. Incompatible storage still requires
separately approved recovery; the module remains disabled.

Final P0-02D.2 results: 29 new pure gate tests / 115 assertions; full host and
container suites each 373 tests / 1,240 assertions; disposable database suite
85 tests / 273 assertions; 26 real lifecycle gate process cases passed. The
original lifecycle gate still exits 2 (18 scenarios / 134 orchestrator assertions /
394 worker assertions). The workspace root-cause report contains exact case
results, evidence paths and limits; no active deployment was invoked.
