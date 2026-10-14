# Bounded upgrade recovery (P0-02D.3)

`recover-module-upgrade.php` is a separately invoked operator command. Nothing in
installation, upgrade, verification, module initialization, lookup or commerce
calls it. It does not bootstrap PrestaShop or execute a module constructor.
The independent verifier, ordinary upgrade, recovery, enablement and deployment
readiness remain separate operations.

## Eligible and blocked states

Recovery is restricted to fixed source and registered version **1.1.0**, matching
PHP/XML metadata, exactly one canonical module registration, global active=0 and
**no module_shop associations**. This is deliberately stronger than a device mask
or disablement in one shop. The target must match literal installed parameters,
actual DATABASE()/server hostname, trusted metadata and explicit configuration.

- Completely absent canonical registry: may create the **empty canonical table**.
- Existing compatible registry: no mutation, including when populated.
- Existing incompatible registry: blocked; no ALTER, replacement, rename, DROP,
  index/constraint repair, row rewrite or version correction.
- Missing/contradictory registration, enabled module/shop association, wrong source
  or registered version, metadata/access uncertainty, changed inspection state,
  similarly named ownership storage or a held migration lock: blocked/fail closed.

Similar-name detection conservatively scans all database table/view names for
`offer` followed by `bind`/`registry`, `bind` followed by `offer`, `ownership`, or
`eob_registry`. A suspected backup/alternate is not adopted or silently removed.
Unrecognized unrelated naming cannot authenticate ownership storage; operators
must investigate deployment history as well as the diagnostic.

## Read-only default and configuration

Use the installed module layout and the private read-only configuration documented
in [README.md](README.md). P0-02D.2 metadata views and SELECT/SHOW VIEW permissions
remain mandatory and unchanged.

```sh
EIND_VERIFY_CONFIG=/private/verification.json \
  php modules/eindsuppliersearch/bin/recover-module-upgrade.php --dry-run --json
```

Omitting action flags also means dry-run. It inspects state, emits a single JSON
object, reports all collected failed preconditions, the target fingerprint and
planned `CREATE_EMPTY_CANONICAL_REGISTRY` action/canonical table name. It never
opens recovery credentials, takes a lock or changes database/filesystem state.
Unknown reads are operational failure, never eligibility. No production/development
invocation, even a dry-run, was performed during this milestone.

Actual recovery needs an additional private `EIND_RECOVERY_CONFIG` JSON file with
exactly the same host/port/database/prefix/server_hostname/metadata_definer as the
read-only configuration, but separate credentials. The writer must have only:

- global USAGE (without extra privileges/roles/grant option);
- database-wide SELECT and SHOW VIEW;
- table-specific CREATE on **only** `<prefix>eind_offer_binding`.

The command verifies actual SHOW GRANTS and the writer's connected database/server.
It rejects broad application/admin credentials. It does not provision accounts,
views or grants. These were created only in isolated test setup. Future active
provisioning and execution require separate explicit authorization. Session SQL
mode must already be strict, with enforced CHECKs/uniqueness. Recovery uses the
generic shared installer and does not change SQL mode.

## Explicit process-local authorization

```sh
EIND_VERIFY_CONFIG=/private/verification.json \
EIND_RECOVERY_CONFIG=/private/recovery.json \
  php modules/eindsuppliersearch/bin/recover-module-upgrade.php --recover --json
```

When the inspected missing-table state is eligible, the process emits one JSON
**challenge on stderr** and waits for a JSON response line on stdin. Stdout remains
one final machine-readable result. The challenge has a random 256-bit token,
plan_fingerprint, expires_at (Unix seconds) and an exact confirmation phrase:
`CREATE EMPTY REGISTRY <plan fingerprint>`.

Send both fields from **that live process's challenge**, after reviewing the target
and dry-run plan:

```json
{"token":"<live challenge token>","confirmation":"CREATE EMPTY REGISTRY <live plan fingerprint>"}
```

No `--yes` option or transferable saved dry-run token is accepted. The nonce is
single-use in its originating process, expires after 120 seconds, and is bound
to the actual target/prefix, module/version, exact CREATE operation and complete
inspection state hashes. Wrong token, phrase, target, plan, changed state or expiry
is rejected. EOF/invalid input rejects recovery. Partial input is nonblocking and
bounded; input is capped at 8 KiB. Expiry and plan validity are checked again after
lock acquisition and reinspection, before CREATE. An interrupted/restarted process
needs a new challenge. No durable token table/file is created. This mechanism does
not authenticate the human: filesystem/account access and operator procedures are
still required. Wall-clock changes and noncooperating administrators are outside
its guarantees; there is no durable cross-process authorization audit store.

A compatible existing registry needs no authorization response because no mutation
is possible on that branch. An enabled module is still blocked there.

## Concurrency and allowed SQL

Recovery acquires the **same** database/table-specific advisory lock as the existing
migration. `OfferBindingSchema::migrationLockName()` exposes the unchanged identity;
the ordinary install path's lock semantics remain unchanged. Recovery uses timeout
0, returning RECOVERY_LOCK_BUSY rather than waiting on another migration/recovery.
All eligibility, target/source/version/disabled states and authorization are
re-read under the lock. Any changed protected state invalidates the plan.

Only the shared `OfferBindingSchema::ddl()` is allowed through the write callback.
The existing installer reacquires the connection-owned reentrant advisory lock,
checks absence, creates and verifies, and balances its acquisition. Recovery holds
its outer acquisition through postconditions and the independent verifier, then
releases it. If a compatible registry appeared before locked reinspection,
recovery reports UNNECESSARY without mutation. Incompatible storage is blocked.

The only persistent mutation is canonical **CREATE TABLE IF NOT EXISTS**, including
its canonical indexes/CHECKs in the same definition. No binding INSERT, native
DML, module state update, ALTER, DROP, repair or enablement SQL is available.
CREATE is not assumed rollbackable. Any table created before interruption/failure
is retained. A subsequent fully compatible retained table is a no-op; incompatible
partial storage remains blocked. No automatic cleanup or rollback is attempted.

Advisory locks serialize cooperating migration/recovery clients; they cannot lock
out ordinary module enablement, other DDL or storefront activity. This command
therefore requires an operator-controlled maintenance window with those writers
quiesced. It rechecks state and detects observed changes, but cannot make module
state and DDL atomic against noncooperating actors. No guarantee of an atomic
snapshot across arbitrary concurrent commerce writes is claimed.

## Postconditions and independent verification

Inspection hashes complete rows of all other persistent tables/views, including
module/version/shop and catalog/commerce records; hashes never expose row contents.
The canonical registry is excluded from the protected-data hash because creation
is the permitted delta. Shared structural verification and an independent count
must show a canonical empty table after this command creates it. Source, target,
registration/shop state and protected data must match the locked baseline exactly.
This full scan is intentionally conservative and may be expensive on large data;
size/resource and maintenance-window suitability require operational review.

After creation (and compatible no-op recovery), the unchanged
`verify-module-upgrade.php --json` runs in a **separate PHP process** using the
read-only account. Its actual process exit and JSON must agree. Recovery only
accepts a canonical registry with the sole failed deployment check being the
required disabled-module enablement check. Other integrity/unknown checks make
recovery incomplete. The verifier's exit 1 is preserved in `verification`, never
overridden or converted to PASS. The command never enables the module.

## Output and exits

JSON contract version 1 contains action, status, exit_code, recovery_eligible,
mutation_performed, planned mutation/canonical table (where collected), database
fingerprint, module/source/registered version, registry_before/after, stable code(s),
deployment_ready=false, and the separate verification result when invoked.
No credentials, raw supplier references, customer/order rows or SQL exception
messages are rendered. A challenge nonce is not a database credential.

| Exit | Meaning |
|---|---|
| 0 | Eligible read-only dry-run, or RECOVERED/UNNECESSARY with verified postconditions |
| 1 | Blocked integrity/eligibility, missing/invalid/expired authorization, state change or busy advisory lock |
| 2 | Operational/environment/metadata failure, postcondition failure or incomplete independent verification |

**Exit 0 never means deployment-ready.** A recovery exit 0 normally contains
independent verification exit 1 because the module remains disabled. Dry-run 0
means a plan was inspected, not executed. `mutation_performed` may be null when a
DDL outcome cannot be established; `ddl_attempted` records an attempted CREATE.
Never interpret an incomplete/unknown result as rollback. No-op outputs preserve
registry rows regardless of population.

Example abbreviated completed result:

```json
{
  "status":"RECOVERED", "exit_code":0,
  "mutation_performed":true, "deployment_ready":false,
  "registered_version":"1.1.0",
  "registry_after":{"exists":true,"schema_valid":true,"rows":0},
  "verification":{"status":"FAIL","exit_code":1,"module_enabled":false}
}
```

## Testing and remaining boundary

Pure tests inject state/read/create/lock/gate/clock dependencies. The unchanged
PDO disposable guard protects database tests. Real lifecycle tests use isolated
accounts/containers/volumes/internal networks and read-only source/application
mounts. A native supported CLI failure with CREATE denied reproduces registered
1.1.0 / disabled / missing registry without manually changing version history.
Native configuration is restored before guarded verification. Recovery assertions
compare full protected rows/DDL; no-op/blocked/dry-run compare the entire database.
Concurrent authorized CLI processes must create exactly once. Populated compatible
and incompatible fixtures are preserved; interruption/verification failure tests
retain canonical creation. No supplier APIs, production or eind_dev are used.

PrestaShop's version-advancement and zero-exit defects remain visible in the
original P0-02D acceptance gate. Recovery does not correct those upstream bugs or
version history. Incompatible storage needs a separate approved migration. Module
enablement/deployment, ownership writers and supplier-offer creation remain outside
this milestone; no automatic caller or active deployment workflow was added.
