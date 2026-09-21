-- =============================================================================
-- ReserveChain.io — 0003  Token programs, reserves, reconciliation, ledger
--
-- Governing constraint for this whole file [M§12]:
--   "Do not hard-code token supply based directly on material valuation, a
--    fixed token price, a fixed token-to-kilogram ratio, a fixed
--    token-to-container or token-to-coil ratio, ownership rights, redemption
--    thresholds, presale discounts, expected appreciation, secondary-market
--    liquidity, contract addresses before deployment approval, token symbols
--    before final authorization, circulating supply before issuance, reserve
--    ratios before reconciliation, or any right to physical title before legal
--    approval."
--
-- So every one of those is a nullable column with a state, not a constant.
-- A null here means "the project owner has not supplied or approved this yet",
-- and the platform is required to show it as pending rather than invent it.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Token programs. One ERC-20 series per approved asset program, with the
-- ability to add further issuer-approved programs without rebuilding the
-- platform. [MP3]
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_token_programs` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id`           BIGINT UNSIGNED NULL,
  `program_key`          VARCHAR(64)     NOT NULL,
  -- Null until the owner authorises the name, symbol and supply. [M§12]
  `token_name`           VARCHAR(128)    NULL,
  `token_symbol`         VARCHAR(32)     NULL,
  `decimals`             TINYINT UNSIGNED NOT NULL DEFAULT 18,
  `max_supply`           DECIMAL(38,0)   NULL,

  `chain_id`             INT UNSIGNED    NULL,
  `chain_name`           VARCHAR(64)     NULL,
  -- Null until deployment is authorised and the address is verified. No
  -- contract address may be published before deployment approval. [M§3, M§12]
  `contract_address`     CHAR(42)        NULL,
  `deployment_tx_hash`   CHAR(66)        NULL,
  `deployed_at`          DATETIME        NULL,
  `is_verified_on_explorer` TINYINT(1)   NOT NULL DEFAULT 0,

  `state`                ENUM('pending','under_review','not_applicable','approved','published','suspended','retired') NOT NULL DEFAULT 'pending',
  `publication_state`    ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',

  -- Reserve ratio policy, as configuration. Null until approved.
  `target_reserve_ratio` DECIMAL(12,6)   NULL,

  `notes`                TEXT            NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_program_key` (`program_key`),
  KEY `idx_token_program_state` (`state`),
  KEY `fk_token_program_asset_program` (`program_id`),
  CONSTRAINT `fk_token_program_asset_program`
    FOREIGN KEY (`program_id`) REFERENCES `{prefix}rc_asset_programs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Supply allocation buckets — treasury, participation, liquidity, reserve,
-- team. Percentages are rows so an authorised administrator can change them
-- without a deployment; vesting and lockup are supported where approved. [MP3]
CREATE TABLE IF NOT EXISTS `{prefix}rc_token_allocations` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_program_id` BIGINT UNSIGNED NOT NULL,
  `allocation_key`   VARCHAR(64)     NOT NULL,
  `label`            VARCHAR(128)    NOT NULL,
  `percentage`       DECIMAL(9,6)    NULL,
  `absolute_amount`  DECIMAL(38,0)   NULL,
  `vesting_months`   SMALLINT UNSIGNED NULL,
  `cliff_months`     SMALLINT UNSIGNED NULL,
  `wallet_address`   CHAR(42)        NULL,
  `state`            ENUM('pending','under_review','not_applicable','approved','published','suspended','retired') NOT NULL DEFAULT 'pending',
  `sort_order`       INT             NOT NULL DEFAULT 0,
  `notes`            TEXT            NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_allocation_key` (`token_program_id`,`allocation_key`),
  CONSTRAINT `fk_allocation_token_program`
    FOREIGN KEY (`token_program_id`) REFERENCES `{prefix}rc_token_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Blockchain synchronisation [MP5]
--
-- Raw on-chain logs, captured by services/chain-sync. Unique on
-- (chain_id, tx_hash, log_index) so replays, retries and reorg recovery cannot
-- double-count — the brief asks explicitly for idempotent transaction
-- processing, duplicate-event prevention and reorganization handling.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_chain_events` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chain_id`         INT UNSIGNED    NOT NULL,
  `block_number`     BIGINT UNSIGNED NOT NULL,
  `block_hash`       CHAR(66)        NOT NULL,
  `tx_hash`          CHAR(66)        NOT NULL,
  `log_index`        INT UNSIGNED    NOT NULL,
  `contract_address` CHAR(42)        NOT NULL,
  `event_name`       VARCHAR(128)    NOT NULL,
  `args_json`        LONGTEXT        NOT NULL,
  `occurred_at`      DATETIME        NOT NULL,
  `confirmations`    INT UNSIGNED    NOT NULL DEFAULT 0,
  -- Set when a reorganisation removes the block that contained this log.
  `is_orphaned`      TINYINT(1)      NOT NULL DEFAULT 0,
  `processed_at`     DATETIME        NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chain_event` (`chain_id`,`tx_hash`,`log_index`),
  KEY `idx_chain_event_contract` (`contract_address`,`event_name`,`block_number`),
  KEY `idx_chain_event_pending` (`is_orphaned`,`processed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexer cursor, so a restart resumes rather than rescanning from genesis.
CREATE TABLE IF NOT EXISTS `{prefix}rc_chain_sync_state` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chain_id`            INT UNSIGNED    NOT NULL,
  `contract_address`    CHAR(42)        NOT NULL,
  `last_block_number`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_block_hash`     CHAR(66)        NULL,
  `last_synced_at`      DATETIME        NULL,
  `status`              ENUM('idle','syncing','error','paused') NOT NULL DEFAULT 'idle',
  `last_error`          TEXT            NULL,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_target` (`chain_id`,`contract_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Point-in-time supply, derived from chain state.
-- [MP5] "Financial or token balances must not be maintained solely as manually
--        editable database fields."
CREATE TABLE IF NOT EXISTS `{prefix}rc_supply_snapshots` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_program_id`   BIGINT UNSIGNED NOT NULL,
  `chain_id`           INT UNSIGNED    NOT NULL,
  `block_number`       BIGINT UNSIGNED NOT NULL,
  `total_supply`       DECIMAL(38,0)   NOT NULL,
  `circulating_supply` DECIMAL(38,0)   NULL,
  `treasury_held`      DECIMAL(38,0)   NULL,
  `locked_supply`      DECIMAL(38,0)   NULL,
  `burned_supply`      DECIMAL(38,0)   NULL,
  `redeemed_supply`    DECIMAL(38,0)   NULL,
  `captured_at`        DATETIME        NOT NULL,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supply_snapshot` (`token_program_id`,`chain_id`,`block_number`),
  KEY `idx_supply_captured` (`captured_at`),
  CONSTRAINT `fk_supply_token_program`
    FOREIGN KEY (`token_program_id`) REFERENCES `{prefix}rc_token_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Proof of Reserves [MP11]
--
-- "The platform must not automatically describe uploaded information as
--  independently verified unless an approved independent verification or
--  attestation has actually been provided."
--
-- Hence `has_independent_attestation` defaults to 0 and the public dashboard
-- renders a different, weaker label when it is false.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_reserve_reports` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_program_id`       BIGINT UNSIGNED NULL,
  `report_code`            VARCHAR(64)     NOT NULL,
  `period_start`           DATE            NULL,
  `period_end`             DATE            NULL,
  `as_of_date`             DATE            NOT NULL,

  `eligible_reserve_value` DECIMAL(24,2)   NULL,
  `tokenized_value`        DECIMAL(24,2)   NULL,
  -- Derived from the two figures above; stored for historical reporting only
  -- and recomputed on every publish rather than trusted as input.
  `coverage_ratio`         DECIMAL(12,6)   NULL,
  `currency`               CHAR(3)         NOT NULL DEFAULT 'USD',
  `valuation_date`         DATE            NULL,
  `attestation_date`       DATE            NULL,

  `has_independent_attestation` TINYINT(1) NOT NULL DEFAULT 0,
  `attestation_provider`   VARCHAR(191)    NULL,
  `document_id`            BIGINT UNSIGNED NULL,

  `publication_state`      ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  -- On-chain anchor of this report's content digest.
  `content_hash`           CHAR(64)        NULL,
  `anchor_tx_hash`         CHAR(66)        NULL,

  `notes`                  TEXT            NULL,
  `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reserve_report_code` (`report_code`),
  KEY `idx_reserve_report_asof` (`as_of_date`),
  KEY `idx_reserve_report_state` (`publication_state`),
  KEY `fk_reserve_report_document` (`document_id`),
  CONSTRAINT `fk_reserve_report_token_program`
    FOREIGN KEY (`token_program_id`) REFERENCES `{prefix}rc_token_programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reserve_report_document`
    FOREIGN KEY (`document_id`) REFERENCES `{prefix}rc_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reserve breakdown by program, lot and individual unit. [MP11]
CREATE TABLE IF NOT EXISTS `{prefix}rc_reserve_lines` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`        BIGINT UNSIGNED NOT NULL,
  `program_id`       BIGINT UNSIGNED NULL,
  `lot_id`           BIGINT UNSIGNED NULL,
  `unit_id`          BIGINT UNSIGNED NULL,
  `quantity`         DECIMAL(24,6)   NULL,
  `quantity_unit`    VARCHAR(16)     NULL,
  `assessed_value`   DECIMAL(24,2)   NULL,
  `currency`         CHAR(3)         NULL,
  `status`           ENUM('not_assessed','eligible','allocated','reconciled','exception','withdrawn') NOT NULL DEFAULT 'eligible',
  `exclusion_reason` VARCHAR(255)    NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reserve_line_report` (`report_id`),
  KEY `idx_reserve_line_lot` (`lot_id`),
  KEY `fk_reserve_line_program` (`program_id`),
  KEY `fk_reserve_line_unit` (`unit_id`),
  CONSTRAINT `fk_reserve_line_report`
    FOREIGN KEY (`report_id`) REFERENCES `{prefix}rc_reserve_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reserve_line_program`
    FOREIGN KEY (`program_id`) REFERENCES `{prefix}rc_asset_programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reserve_line_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reserve_line_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `{prefix}rc_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Reconciliation [MP11, MP17]
-- "The system must detect and report inconsistencies rather than silently
--  adjusting balances."
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_reconciliation_runs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_type`        VARCHAR(64)     NOT NULL,
  `started_at`      DATETIME(3)     NOT NULL,
  `finished_at`     DATETIME(3)     NULL,
  `status`          ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  `checked_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `exception_count` INT UNSIGNED    NOT NULL DEFAULT 0,
  `summary_json`    LONGTEXT        NULL,
  `triggered_by`    BIGINT UNSIGNED NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recon_run` (`run_type`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}rc_reconciliation_exceptions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id`           BIGINT UNSIGNED NOT NULL,
  -- supply_mismatch | reserve_shortfall | stale_valuation | stale_attestation |
  -- missing_document | expiring_document | unverified_quantity |
  -- orphaned_token | custody_gap | ledger_drift | declared_vs_verified
  `code`             VARCHAR(64)     NOT NULL,
  `severity`         ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'warning',
  `entity_type`      VARCHAR(64)     NULL,
  `entity_id`        BIGINT UNSIGNED NULL,
  `message`          TEXT            NOT NULL,
  `detail_json`      LONGTEXT        NULL,
  `status`           ENUM('open','acknowledged','resolved','suppressed') NOT NULL DEFAULT 'open',
  `acknowledged_by`  BIGINT UNSIGNED NULL,
  `acknowledged_at`  DATETIME        NULL,
  `resolved_at`      DATETIME        NULL,
  `resolution_note`  TEXT            NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recon_exception_status` (`status`,`severity`),
  KEY `idx_recon_exception_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_recon_exception_run`
    FOREIGN KEY (`run_id`) REFERENCES `{prefix}rc_reconciliation_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Financial ledger [MP17]
--
-- Every balance-affecting action gets an entry carrying its supporting
-- transaction, reason, timestamp, actor, wallet or reference. Balances are
-- derived by summing entries; nothing is edited in place. Corrections are
-- posted as reversing entries. Append-only, enforced by trigger in 0005.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_ledger_entries` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- treasury | liquidity | fee | redemption | burn | reserve | acquisition |
  -- structuring_cost | spread | licensing_revenue | enterprise_revenue
  `ledger_key`     VARCHAR(64)     NOT NULL,
  `entry_type`     VARCHAR(64)     NOT NULL,
  `direction`      ENUM('debit','credit') NOT NULL,
  `amount`         DECIMAL(38,18)  NOT NULL,
  `currency`       VARCHAR(16)     NOT NULL,
  `entity_type`    VARCHAR(64)     NULL,
  `entity_id`      BIGINT UNSIGNED NULL,
  `counterparty`   VARCHAR(191)    NULL,
  `tx_hash`        CHAR(66)        NULL,
  `wallet_address` CHAR(42)        NULL,
  `reference`      VARCHAR(191)    NULL,
  `reason`         TEXT            NULL,
  -- Set on a reversing entry to point at the entry it corrects.
  `reverses_id`    BIGINT UNSIGNED NULL,
  `occurred_at`    DATETIME(3)     NOT NULL,
  `recorded_by`    BIGINT UNSIGNED NULL,
  `audit_log_id`   BIGINT UNSIGNED NULL,
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_ledger_key_time` (`ledger_key`,`occurred_at`),
  KEY `idx_ledger_entity` (`entity_type`,`entity_id`),
  KEY `idx_ledger_reverses` (`reverses_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
