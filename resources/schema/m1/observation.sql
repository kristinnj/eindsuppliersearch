CREATE TABLE P_eind_observation (
  observation_id BINARY(16) NOT NULL,
  id_shop INT UNSIGNED NOT NULL,
  id_supplier INT UNSIGNED NOT NULL,
  order_reference MEDIUMBLOB NOT NULL,
  reference_sha256 BINARY(32) NOT NULL,
  origin VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  adapter_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  adapter_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  auth_scope BINARY(16) NOT NULL,
  observed_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  config_sha256 BINARY(32) NOT NULL,
  policy_sha256 BINARY(32) NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unit_price_tax_excl DECIMAL(20,6) NOT NULL,
  available_quantity INT UNSIGNED NOT NULL,
  schema_version SMALLINT UNSIGNED NOT NULL,
  payload MEDIUMBLOB NOT NULL,
  payload_sha256 BINARY(32) NOT NULL,
  key_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_mac BINARY(32) NOT NULL,
  PRIMARY KEY (observation_id),
  KEY ix_obs_identity (id_shop,id_supplier,reference_sha256,observed_at),
  KEY ix_obs_scope_expiry (auth_scope,expires_at),
  CONSTRAINT ck_obs_scope CHECK (id_shop>0 AND id_supplier>0),
  CONSTRAINT ck_obs_ref CHECK (OCTET_LENGTH(order_reference) BETWEEN 1 AND 4096
    AND UNHEX(SHA2(order_reference,256)) IS NOT NULL
    AND reference_sha256=UNHEX(SHA2(order_reference,256))),
  CONSTRAINT ck_obs_values CHECK (schema_version=1 AND expires_at>observed_at
    AND CAST(origin AS BINARY) IN ('LIVE','FIXTURE')
    AND currency REGEXP BINARY '^[A-Z]{3}$' AND unit_price_tax_excl>0),
  CONSTRAINT ck_obs_payload CHECK (OCTET_LENGTH(payload) BETWEEN 1 AND 1048576
    AND UNHEX(SHA2(payload,256)) IS NOT NULL
    AND payload_sha256=UNHEX(SHA2(payload,256)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;