-- =============================================================================
-- ReserveChain.io — 0001  Platform configuration, security and audit trail
--
-- Requirement references:
--   [M§n]  ReserveChain_Final_Master_Developer_Instructions_v2.0.pdf, section n
--   [MPn]  ...the same document, Phase n
--   [W§n]  ReserveChain_Website_Developer_Instructions.pdf, section n
--
-- Editorial content (the 51 pages, FAQ, announcements, legal notices) lives in
-- WordPress custom post types so the client's editors get the native editor,
-- revisions and media library. The asset registry below lives in dedicated
-- relational tables: it is referential data with integrity constraints,
-- unit-level reconciliation and reporting requirements that post meta cannot
-- express or query at scale.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Migration bookkeeping
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_migrations` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`    VARCHAR(191)    NOT NULL,
  `checksum`    CHAR(64)        NOT NULL,
  `applied_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duration_ms` INT UNSIGNED    NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Settings. Every commercial and operational parameter is a row, never a
-- constant: "no hard-coded percentage, price, spread, fee or discount that
-- cannot be changed by an authorized issuer administrator". [M§12, MP3]
--
-- Rows flagged `requires_approval` follow maker-checker — one administrator
-- proposes the change, a different administrator approves it. [MP3, MP9]
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_settings` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`        VARCHAR(191)    NOT NULL,
  `setting_group`      VARCHAR(64)     NOT NULL DEFAULT 'general',
  `value_json`         LONGTEXT        NULL,
  `value_type`         VARCHAR(32)     NOT NULL DEFAULT 'string',
  `label`              VARCHAR(255)    NULL,
  `description`        TEXT            NULL,
  `requires_approval`  TINYINT(1)      NOT NULL DEFAULT 0,
  `approval_state`     ENUM('none','proposed','approved','rejected') NOT NULL DEFAULT 'none',
  `pending_value_json` LONGTEXT        NULL,
  `proposed_by`        BIGINT UNSIGNED NULL,
  `proposed_at`        DATETIME        NULL,
  `approved_by`        BIGINT UNSIGNED NULL,
  `approved_at`        DATETIME        NULL,
  -- Secret values are never returned by a read API and never rendered.
  `is_secret`          TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`),
  KEY `idx_setting_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Website modes. [M§18]
-- "Live Offering, token purchase, wallet, payment, eligibility and redemption
--  functions must require explicit authorized administrative and deployment
--  actions. They must never activate automatically."
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_site_modes` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mode_key`               VARCHAR(64)     NOT NULL,
  `label`                  VARCHAR(191)    NOT NULL,
  `description`            TEXT            NULL,
  `is_active`              TINYINT(1)      NOT NULL DEFAULT 0,
  -- A high-risk mode cannot be toggled by one administrator acting alone.
  `is_high_risk`           TINYINT(1)      NOT NULL DEFAULT 1,
  `requires_written_authorization` TINYINT(1) NOT NULL DEFAULT 1,
  `authorization_reference` VARCHAR(255)   NULL,
  `approval_state`         ENUM('none','proposed','approved','rejected') NOT NULL DEFAULT 'none',
  `proposed_state`         TINYINT(1)      NULL,
  `proposed_by`            BIGINT UNSIGNED NULL,
  `proposed_at`            DATETIME        NULL,
  `approved_by`            BIGINT UNSIGNED NULL,
  `approved_at`            DATETIME        NULL,
  `activated_at`           DATETIME        NULL,
  `deactivated_at`         DATETIME        NULL,
  `sort_order`             INT             NOT NULL DEFAULT 0,
  `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mode_key` (`mode_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Module visibility. [M§17] Modules are built complete, then held hidden,
-- inactive and non-indexable until expressly authorised.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_module_flags` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key`      VARCHAR(64)     NOT NULL,
  `label`           VARCHAR(191)    NOT NULL,
  `description`     TEXT            NULL,
  `state`           ENUM('built_inactive','staged','active','retired') NOT NULL DEFAULT 'built_inactive',
  `is_public`       TINYINT(1)      NOT NULL DEFAULT 0,
  `is_indexable`    TINYINT(1)      NOT NULL DEFAULT 0,
  `depends_on_mode` VARCHAR(64)     NULL,
  `activation_note` TEXT            NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Legal notices and disclosures, versioned per locale. [M§4]
--
-- The mandatory no-offer disclosure lives here rather than in a template, for
-- two reasons. Legal review will revise the wording, and that must not require
-- a code deployment. And consent evidence has to be reproducible: when a
-- registrant is asked years later what they agreed to, the platform must be
-- able to show the exact text that was on screen on that date, which is only
-- possible if every version is retained.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_legal_notices` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`               VARCHAR(64)     NOT NULL,
  `locale`            VARCHAR(10)     NOT NULL DEFAULT 'en',
  `version`           INT UNSIGNED    NOT NULL DEFAULT 1,
  `title`             VARCHAR(255)    NOT NULL,
  `body`              LONGTEXT        NOT NULL,
  `source_reference`  VARCHAR(255)    NULL,
  `publication_state` ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `effective_from`    DATETIME        NULL,
  `effective_to`      DATETIME        NULL,
  `approved_by`       BIGINT UNSIGNED NULL,
  `approved_at`       DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notice_locale_version` (`key`,`locale`,`version`),
  KEY `idx_notice_state` (`key`,`publication_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Jurisdiction controls. [M§5]
-- Default is 'undetermined': the permitted-jurisdiction list is owner and
-- adviser input that has not been supplied, and missing information is never
-- invented. EU/EEA is flagged because ReserveChain does not currently intend
-- to offer tokens there, and the project must not be marketed as
-- MiCA-compliant.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_jurisdictions` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `country_code`          CHAR(2)         NOT NULL,
  `country_name`          VARCHAR(128)    NOT NULL,
  `region`                VARCHAR(64)     NULL,
  `status`                ENUM('undetermined','permitted','restricted','blocked') NOT NULL DEFAULT 'undetermined',
  `is_eu_eea`             TINYINT(1)      NOT NULL DEFAULT 0,
  `requires_enhanced_dd`  TINYINT(1)      NOT NULL DEFAULT 0,
  `investor_classification_required` VARCHAR(128) NULL,
  `notes`                 TEXT            NULL,
  `source_reference`      VARCHAR(255)    NULL,
  `decided_by`            BIGINT UNSIGNED NULL,
  `decided_at`            DATETIME        NULL,
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_country_code` (`country_code`),
  KEY `idx_jurisdiction_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Owner-input register. [Master, Part III §B]
-- Tracks every input the project owner or appointed advisers must supply,
-- with status, source, version, approval authority and affected components.
-- Pending input never permits omitting the corresponding module or field.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_owner_inputs` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`            VARCHAR(64)     NOT NULL,
  `input_key`           VARCHAR(128)    NOT NULL,
  `required_input`      TEXT            NOT NULL,
  `status`              ENUM('pending','supplied','approved','superseded') NOT NULL DEFAULT 'pending',
  `source`              VARCHAR(255)    NULL,
  `version`             VARCHAR(64)     NULL,
  `approval_authority`  VARCHAR(191)    NULL,
  `affected_components` TEXT            NULL,
  `supplied_at`         DATETIME        NULL,
  `approved_at`         DATETIME        NULL,
  `notes`               TEXT            NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_owner_input_key` (`input_key`),
  KEY `idx_owner_input_cat_status` (`category`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SECURITY
--
-- Identity stays in WordPress users, so the client's team manages accounts the
-- way they already know. What WordPress does not provide, we add: enforced
-- TOTP multi-factor authentication and login-attempt protection. [M§16]
-- =============================================================================

CREATE TABLE IF NOT EXISTS `{prefix}rc_mfa_credentials` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `type`          VARCHAR(32)     NOT NULL DEFAULT 'totp',
  `label`         VARCHAR(128)    NULL,
  -- Encrypted with the application key; never returned by any endpoint.
  `secret_cipher` VARBINARY(512)  NOT NULL,
  `confirmed_at`  DATETIME        NULL,
  `last_used_at`  DATETIME        NULL,
  -- Hashes of unused single-use recovery codes.
  `recovery_codes` TEXT           NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfa_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}rc_login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`        VARCHAR(191)    NULL,
  `ip_address`   VARBINARY(16)   NULL,
  `successful`   TINYINT(1)      NOT NULL DEFAULT 0,
  `failure_code` VARCHAR(64)     NULL,
  `user_agent`   VARCHAR(255)    NULL,
  `attempted_at` DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_attempt_login` (`login`,`attempted_at`),
  KEY `idx_attempt_ip` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- APPEND-ONLY, TAMPER-EVIDENT AUDIT TRAIL  [M§16]
--
-- "A complete append-only and tamper-evident administrative audit trail is
--  mandatory. Audit records must not be editable or removable through the
--  standard administrative interface."
--
-- Four independent defences, so the guarantee never rests on application code
-- alone:
--   1. No update or delete path exists anywhere in the plugin.
--   2. The runtime database user holds INSERT and SELECT only on this table
--      (infra/docker/mysql-init/10-audit-grants.sql).
--   3. BEFORE UPDATE and BEFORE DELETE triggers raise SQLSTATE '45000', so
--      even a direct statement from a privileged session is rejected
--      (0002_audit_immutability.sql).
--   4. Every row chains to its predecessor —
--        row_hash = SHA256( prev_hash || canonical_json(payload) )
--      — and a daily Merkle root is anchored on-chain, so tampering stays
--      detectable to an outside auditor even against full database access.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `{prefix}rc_audit_log` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `occurred_at`      DATETIME(6)     NOT NULL,
  `actor_user_id`    BIGINT UNSIGNED NULL,
  `actor_label`      VARCHAR(191)    NULL,
  `actor_roles`      VARCHAR(255)    NULL,
  `actor_ip`         VARBINARY(16)   NULL,
  `actor_user_agent` VARCHAR(255)    NULL,
  `request_id`       CHAR(36)        NULL,
  -- create | update | state_change | publish | unpublish | archive | approve |
  -- reject | upload | replace | download | export | login_success |
  -- login_failure | role_change | mode_change | setting_change | anchor
  `action`           VARCHAR(64)     NOT NULL,
  `entity_type`      VARCHAR(64)     NOT NULL,
  `entity_id`        BIGINT UNSIGNED NULL,
  `entity_label`     VARCHAR(255)    NULL,
  `field`            VARCHAR(191)    NULL,
  `previous_value`   LONGTEXT        NULL,
  `new_value`        LONGTEXT        NULL,
  -- Required for material changes. [M§16 "Reason for material changes"]
  `reason`           TEXT            NULL,
  `severity`         ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `prev_hash`        CHAR(64)        NOT NULL,
  `row_hash`         CHAR(64)        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_row_hash` (`row_hash`),
  KEY `idx_audit_occurred` (`occurred_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_actor` (`actor_user_id`),
  KEY `idx_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row head pointer for the hash chain.
--
-- Appending means reading the current head, deriving the next hash from it and
-- advancing it. Those three steps must not interleave across concurrent
-- requests or the chain forks. Serialising on `SELECT ... FOR UPDATE` of this
-- row, inside the same transaction as the insert, makes the append atomic —
-- which a MySQL advisory lock would not guarantee under a connection pool.
CREATE TABLE IF NOT EXISTS `{prefix}rc_audit_chain_head` (
  `id`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `head_hash`   CHAR(64)         NOT NULL,
  `entry_count` BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  `updated_at`  DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Genesis: the chain starts from a fixed, publicly documented value so an
-- auditor can recompute it from zero without trusting us for a starting point.
INSERT INTO `{prefix}rc_audit_chain_head` (`id`, `head_hash`, `entry_count`)
VALUES (1, '0000000000000000000000000000000000000000000000000000000000000000', 0)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Daily Merkle anchors of the audit chain, published on-chain.
CREATE TABLE IF NOT EXISTS `{prefix}rc_audit_anchors` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_start`     DATETIME(6)     NOT NULL,
  `period_end`       DATETIME(6)     NOT NULL,
  `first_audit_id`   BIGINT UNSIGNED NOT NULL,
  `last_audit_id`    BIGINT UNSIGNED NOT NULL,
  `entry_count`      INT UNSIGNED    NOT NULL,
  `merkle_root`      CHAR(66)        NOT NULL,
  `chain_id`         INT UNSIGNED    NULL,
  `contract_address` CHAR(42)        NULL,
  `tx_hash`          CHAR(66)        NULL,
  `block_number`     BIGINT UNSIGNED NULL,
  `anchored_at`      DATETIME        NULL,
  `status`           ENUM('pending','submitted','confirmed','failed') NOT NULL DEFAULT 'pending',
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_anchor_period` (`period_start`,`period_end`),
  KEY `idx_anchor_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full JSON snapshot per record version — "record versioning" and the
-- "complete change and approval history". [M§9, MP10]
CREATE TABLE IF NOT EXISTS `{prefix}rc_record_revisions` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`       VARCHAR(64)     NOT NULL,
  `entity_id`         BIGINT UNSIGNED NOT NULL,
  `version`           INT UNSIGNED    NOT NULL,
  `snapshot_json`     LONGTEXT        NOT NULL,
  `publication_state` VARCHAR(32)     NULL,
  `changed_by`        BIGINT UNSIGNED NULL,
  `change_reason`     TEXT            NULL,
  `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_revision_entity_version` (`entity_type`,`entity_id`,`version`),
  KEY `idx_revision_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
