CREATE TABLE P_eind_quote (
  quote_id BINARY(16) NOT NULL,
  observation_id BINARY(16) NOT NULL,
  auth_scope BINARY(16) NOT NULL,
  purpose VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quote_version SMALLINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  id_currency INT UNSIGNED NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unit_price_tax_excl DECIMAL(20,6) NOT NULL,
  id_tax_rules_group INT UNSIGNED NOT NULL,
  issued_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  policy_sha256 BINARY(32) NOT NULL,
  payload MEDIUMBLOB NOT NULL,
  payload_sha256 BINARY(32) NOT NULL,
  key_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_mac BINARY(32) NOT NULL,
  PRIMARY KEY (quote_id),
  KEY ix_quote_observation (observation_id),
  KEY ix_quote_scope_expiry (auth_scope,expires_at),
  CONSTRAINT ck_quote_values CHECK (quote_version=1 AND quantity>0
    AND id_currency>0 AND unit_price_tax_excl>0 AND expires_at>issued_at
    AND currency REGEXP BINARY '^[A-Z]{3}$'
    AND CAST(purpose AS BINARY) IN ('CREATE','CART','CHECKOUT')),
  CONSTRAINT ck_quote_payload CHECK (OCTET_LENGTH(payload) BETWEEN 1 AND 1048576
    AND UNHEX(SHA2(payload,256)) IS NOT NULL
    AND payload_sha256=UNHEX(SHA2(payload,256)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;