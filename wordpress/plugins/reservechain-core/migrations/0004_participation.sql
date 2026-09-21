-- =============================================================================
-- ReserveChain.io — 0004  Waitlist, enquiries, participants, KYC, redemption
--
-- Everything here is built complete and held inactive where the brief requires
-- it. [M§17, M§18, MP7, MP8, MP12] The structure exists now so approved
-- information and authorisations can switch it on later without a rebuild.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Project waitlist [M§15]
--
-- Exactly the fields the brief specifies, and deliberately no others:
--   "No funds, payment details, wallet addresses or token reservations may be
--    accepted through the waitlist."
-- There is no column here in which such data could be stored, so the
-- prohibition is structural rather than a rule someone has to remember.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_waitlist_registrations` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`             CHAR(26)        NOT NULL,
  `first_name`            VARCHAR(128)    NOT NULL,
  `last_name`             VARCHAR(128)    NOT NULL,
  `email`                 VARCHAR(191)    NOT NULL,
  `country_of_residence`  CHAR(2)         NOT NULL,

  `participant_type`      ENUM('individual','institutional') NOT NULL DEFAULT 'individual',
  `is_industrial_buyer`   TINYINT(1)      NOT NULL DEFAULT 0,
  `is_asset_owner_originator` TINYINT(1)  NOT NULL DEFAULT 0,
  `organisation_name`     VARCHAR(191)    NULL,

  `material_interest`     ENUM('copper_powder','nickel_wire','both','future_programs') NOT NULL,
  -- A banded expression of interest, never a monetary commitment.
  `interest_range`        VARCHAR(64)     NULL,
  `intended_participation_type` VARCHAR(64) NULL,

  -- Supported technically, activated only when legally required. [M§15]
  `nationality`           CHAR(2)         NULL,
  `current_location`      CHAR(2)         NULL,

  -- "Email-address verification must be completed before registration is
  --  treated as confirmed." [M§15]
  `email_verified_at`     DATETIME        NULL,
  `verification_token_hash` CHAR(64)      NULL,
  `verification_sent_at`  DATETIME        NULL,
  `verification_expires_at` DATETIME      NULL,
  `verification_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,

  `subscription_status`   ENUM('pending','confirmed','unsubscribed','bounced','suppressed') NOT NULL DEFAULT 'pending',
  `unsubscribed_at`       DATETIME        NULL,

  `registration_source`   VARCHAR(128)    NULL,
  `campaign_source`       VARCHAR(128)    NULL,
  `referrer_url`          VARCHAR(512)    NULL,
  `ip_address`            VARBINARY(16)   NULL,
  `user_agent`            VARCHAR(255)    NULL,

  `internal_notes`        TEXT            NULL,
  `assigned_to`           BIGINT UNSIGNED NULL,

  -- "Correct, anonymize or delete data under approved retention
  --  procedures." [M§15]
  `anonymised_at`         DATETIME        NULL,
  `deleted_at`            DATETIME        NULL,

  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_waitlist_public_id` (`public_id`),
  UNIQUE KEY `uq_waitlist_verification_token` (`verification_token_hash`),
  KEY `idx_waitlist_email` (`email`),
  KEY `idx_waitlist_country` (`country_of_residence`),
  KEY `idx_waitlist_type` (`participant_type`),
  KEY `idx_waitlist_material` (`material_interest`),
  KEY `idx_waitlist_status` (`subscription_status`),
  KEY `idx_waitlist_campaign` (`campaign_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consent evidence: what was agreed, to which document version, when, from
-- where. Retained even after withdrawal. [M§15, MP6]
CREATE TABLE IF NOT EXISTS `{prefix}rc_consents` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waitlist_id`      BIGINT UNSIGNED NULL,
  `user_id`          BIGINT UNSIGNED NULL,
  -- updates | privacy | risk | terms | no_offer_acknowledgement
  `consent_type`     VARCHAR(64)     NOT NULL,
  `granted`          TINYINT(1)      NOT NULL DEFAULT 1,
  `document_key`     VARCHAR(64)     NULL,
  `document_version` INT UNSIGNED    NULL,
  -- Snapshot of the exact wording shown, so consent can be evidenced even
  -- after the notice is revised.
  `text_snapshot`    TEXT            NULL,
  `ip_address`       VARBINARY(16)   NULL,
  `user_agent`       VARCHAR(255)    NULL,
  `granted_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `withdrawn_at`     DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `idx_consent_waitlist` (`waitlist_id`),
  KEY `idx_consent_type` (`consent_type`),
  CONSTRAINT `fk_consent_waitlist`
    FOREIGN KEY (`waitlist_id`) REFERENCES `{prefix}rc_waitlist_registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact and enterprise enquiries, routed by subject. [W§4 page 6]
CREATE TABLE IF NOT EXISTS `{prefix}rc_enquiries` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`      CHAR(26)        NOT NULL,
  -- general | asset_owner | industrial_buyer | institution | enterprise |
  -- licensing | media | compliance | support | early_participation
  `category`       VARCHAR(64)     NOT NULL,
  `first_name`     VARCHAR(128)    NULL,
  `last_name`      VARCHAR(128)    NULL,
  `email`          VARCHAR(191)    NOT NULL,
  `organisation`   VARCHAR(191)    NULL,
  `country_code`   CHAR(2)         NULL,
  `subject`        VARCHAR(255)    NULL,
  `message`        TEXT            NOT NULL,
  `status`         ENUM('new','in_progress','responded','closed','spam') NOT NULL DEFAULT 'new',
  `assigned_to`    BIGINT UNSIGNED NULL,
  `internal_notes` TEXT            NULL,
  `ip_address`     VARBINARY(16)   NULL,
  `user_agent`     VARCHAR(255)    NULL,
  `responded_at`   DATETIME        NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enquiry_public_id` (`public_id`),
  KEY `idx_enquiry_category_status` (`category`,`status`),
  KEY `idx_enquiry_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Participants and compliance [MP6, MP8]
--
-- Identity itself stays in WordPress users; this table carries the
-- participation profile. Wallet, purchase, Proof-of-Reserves and redemption
-- functions remain inactive until authorised.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_participants` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                BIGINT UNSIGNED NOT NULL,
  `public_id`              CHAR(26)        NOT NULL,
  `participant_type`       ENUM('individual','institutional') NOT NULL DEFAULT 'individual',
  `organisation_name`      VARCHAR(191)    NULL,
  `country_of_residence`   CHAR(2)         NULL,
  `nationality`            CHAR(2)         NULL,
  -- Eligibility is a recorded decision, never an assumption. [MP6]
  `eligibility_status`     ENUM('not_assessed','pending','approved','rejected','suspended','expired') NOT NULL DEFAULT 'not_assessed',
  `eligibility_decided_at` DATETIME        NULL,
  `eligibility_decided_by` BIGINT UNSIGNED NULL,
  `risk_rating`            ENUM('unrated','low','medium','high') NOT NULL DEFAULT 'unrated',
  `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_participant_user` (`user_id`),
  UNIQUE KEY `uq_participant_public_id` (`public_id`),
  KEY `idx_participant_eligibility` (`eligibility_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KYC / KYB case with a full, explainable decision trail. The provider is
-- selected by the project owner; we integrate whichever is chosen. [MP6]
CREATE TABLE IF NOT EXISTS `{prefix}rc_kyc_cases` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `participant_id`        BIGINT UNSIGNED NOT NULL,
  `case_type`             ENUM('kyc','kyb') NOT NULL DEFAULT 'kyc',
  `provider`              VARCHAR(64)     NULL,
  `provider_case_id`      VARCHAR(191)    NULL,
  `status`                ENUM('not_started','in_progress','submitted','in_review','approved','rejected','resubmission_required','expired') NOT NULL DEFAULT 'not_started',
  -- Individual screening outcomes, so a decision can be explained rather than
  -- asserted. [MP6]
  `identity_verified`     TINYINT(1)      NOT NULL DEFAULT 0,
  `liveness_verified`     TINYINT(1)      NOT NULL DEFAULT 0,
  `sanctions_hit`         TINYINT(1)      NOT NULL DEFAULT 0,
  `pep_hit`               TINYINT(1)      NOT NULL DEFAULT 0,
  `adverse_media_hit`     TINYINT(1)      NOT NULL DEFAULT 0,
  `source_of_funds_declared` TINYINT(1)   NOT NULL DEFAULT 0,
  `source_of_wealth_required` TINYINT(1)  NOT NULL DEFAULT 0,
  `enhanced_due_diligence` TINYINT(1)     NOT NULL DEFAULT 0,
  `beneficial_owners_collected` TINYINT(1) NOT NULL DEFAULT 0,
  `decision`              ENUM('pending','approved','rejected','referred') NULL,
  `decision_reason`       TEXT            NULL,
  `decided_by`            BIGINT UNSIGNED NULL,
  `decided_at`            DATETIME        NULL,
  `next_review_due`       DATE            NULL,
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kyc_status` (`status`),
  KEY `idx_kyc_review_due` (`next_review_due`),
  CONSTRAINT `fk_kyc_participant`
    FOREIGN KEY (`participant_id`) REFERENCES `{prefix}rc_participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wallet addresses, proven by signature rather than asserted. [MP7, MP12]
-- Inactive in pre-launch: wallet connection is not offered in public
-- pre-launch mode. [M§3]
CREATE TABLE IF NOT EXISTS `{prefix}rc_participant_wallets` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `participant_id`  BIGINT UNSIGNED NOT NULL,
  `chain_id`        INT UNSIGNED    NOT NULL,
  `address`         CHAR(42)        NOT NULL,
  `proof_signature` VARCHAR(255)    NULL,
  `proof_message`   VARCHAR(512)    NULL,
  `verified_at`     DATETIME        NULL,
  `screening_status` ENUM('not_screened','clear','flagged','blocked') NOT NULL DEFAULT 'not_screened',
  `screened_at`     DATETIME        NULL,
  `is_primary`      TINYINT(1)      NOT NULL DEFAULT 0,
  `revoked_at`      DATETIME        NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_participant_wallet` (`participant_id`,`chain_id`,`address`),
  KEY `idx_wallet_address` (`address`),
  CONSTRAINT `fk_wallet_participant`
    FOREIGN KEY (`participant_id`) REFERENCES `{prefix}rc_participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Physical redemption [MP12]
--
-- Built complete, held inactive until authorised. Legal redemption rights,
-- minimum amounts, delivery conditions, fees, taxes and custody rules are all
-- owner-supplied configuration — "the developer must make the relevant rules
-- configurable rather than hard-coding unapproved assumptions".
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_redemption_requests` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`             CHAR(26)        NOT NULL,
  `participant_id`        BIGINT UNSIGNED NOT NULL,
  `token_program_id`      BIGINT UNSIGNED NULL,

  `requested_quantity`    DECIMAL(24,6)   NULL,
  `quantity_unit`         VARCHAR(16)     NULL,
  `token_amount`          DECIMAL(38,0)   NULL,

  `status`                ENUM('not_available','available','requested','locked','approved','burned','released','delivered','cancelled') NOT NULL DEFAULT 'requested',
  -- Compliance is re-checked at redemption time, not inherited from
  -- onboarding. [MP12]
  `compliance_rechecked_at` DATETIME      NULL,
  `jurisdiction_check_passed` TINYINT(1)  NULL,

  -- Cost breakdown; every component configurable, none hard-coded.
  `redemption_fee`        DECIMAL(24,2)   NULL,
  `custody_charge`        DECIMAL(24,2)   NULL,
  `packaging_charge`      DECIMAL(24,2)   NULL,
  `shipping_charge`       DECIMAL(24,2)   NULL,
  `insurance_charge`      DECIMAL(24,2)   NULL,
  `tax_amount`            DECIMAL(24,2)   NULL,
  `total_cost`            DECIMAL(24,2)   NULL,
  `currency`              CHAR(3)         NOT NULL DEFAULT 'USD',

  `delivery_method`       VARCHAR(64)     NULL,
  `delivery_address`      TEXT            NULL,
  `customs_reference`     VARCHAR(191)    NULL,

  `terms_accepted_at`     DATETIME        NULL,
  `signature_reference`   VARCHAR(191)    NULL,

  -- Maker-checker: review and approval must be different administrators. [MP12]
  `reviewed_by`           BIGINT UNSIGNED NULL,
  `reviewed_at`           DATETIME        NULL,
  `approved_by`           BIGINT UNSIGNED NULL,
  `approved_at`           DATETIME        NULL,
  `rejection_reason`      TEXT            NULL,

  `token_locked_at`       DATETIME        NULL,
  `burn_tx_hash`          CHAR(66)        NULL,
  `burned_at`             DATETIME        NULL,
  `custody_release_ref`   VARCHAR(191)    NULL,
  `shipped_at`            DATETIME        NULL,
  `delivered_at`          DATETIME        NULL,
  `cancelled_at`          DATETIME        NULL,

  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_redemption_public_id` (`public_id`),
  KEY `idx_redemption_status` (`status`),
  KEY `idx_redemption_participant` (`participant_id`),
  KEY `fk_redemption_token_program` (`token_program_id`),
  CONSTRAINT `fk_redemption_participant`
    FOREIGN KEY (`participant_id`) REFERENCES `{prefix}rc_participants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_redemption_token_program`
    FOREIGN KEY (`token_program_id`) REFERENCES `{prefix}rc_token_programs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The specific containers or coils allocated to a redemption, with
-- substitution rules where approved. [MP12]
CREATE TABLE IF NOT EXISTS `{prefix}rc_redemption_units` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `redemption_request_id`   BIGINT UNSIGNED NOT NULL,
  `unit_id`                 BIGINT UNSIGNED NOT NULL,
  `allocated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at`             DATETIME        NULL,
  `substituted_for_unit_id` BIGINT UNSIGNED NULL,
  `substitution_reason`     TEXT            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_redemption_unit` (`redemption_request_id`,`unit_id`),
  KEY `fk_redemption_unit_unit` (`unit_id`),
  CONSTRAINT `fk_redemption_unit_request`
    FOREIGN KEY (`redemption_request_id`) REFERENCES `{prefix}rc_redemption_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_redemption_unit_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `{prefix}rc_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Notifications and support
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_notifications` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`      ENUM('email','push','sms','in_app') NOT NULL DEFAULT 'email',
  `template_key` VARCHAR(64)     NOT NULL,
  `recipient`    VARCHAR(191)    NOT NULL,
  `user_id`      BIGINT UNSIGNED NULL,
  `subject`      VARCHAR(255)    NULL,
  `payload_json` LONGTEXT        NULL,
  `status`       ENUM('queued','sending','sent','failed','suppressed') NOT NULL DEFAULT 'queued',
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`   TEXT            NULL,
  `sent_at`      DATETIME        NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notification_status` (`status`,`created_at`),
  KEY `idx_notification_recipient` (`recipient`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}rc_support_tickets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`   CHAR(26)        NOT NULL,
  `user_id`     BIGINT UNSIGNED NULL,
  `email`       VARCHAR(191)    NULL,
  `category`    VARCHAR(64)     NOT NULL,
  `subject`     VARCHAR(255)    NOT NULL,
  `body`        TEXT            NOT NULL,
  `status`      ENUM('open','in_progress','waiting_on_user','resolved','closed') NOT NULL DEFAULT 'open',
  `priority`    ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `assigned_to` BIGINT UNSIGNED NULL,
  `resolved_at` DATETIME        NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_public_id` (`public_id`),
  KEY `idx_ticket_status` (`status`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
