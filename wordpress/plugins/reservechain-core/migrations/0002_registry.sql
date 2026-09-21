-- =============================================================================
-- ReserveChain.io — 0002  Industrial metals registry
--
-- Hierarchy:  asset program → lot / batch → unit (container, coil, bobbin,
--             ampoule, box). Every level carries its own documents, custody,
--             valuation and reserve state, because the brief requires
--             container-by-container and coil-by-coil identification,
--             inventory reconciliation and redemption control. [M§10, M§11]
--
-- Two modelling decisions that run through the whole file:
--
--  1. DECLARED vs VERIFIED are always separate columns. The supplied
--     certificates state that net weight is "according to information given by
--     customer" — a declaration, not an independent measurement. Collapsing
--     the two would manufacture an assurance nobody has given. [M§3, W§8]
--
--  2. Every status column defaults to its weakest value. Nothing is
--     "verified", "in custody", "insured" or "tokenized" until a record and an
--     approval say so. [M§1, W§8 "No false status labels"]
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Documents and evidence
--
-- Files live in private object storage and are served only through signed,
-- permission-checked URLs. The SHA-256 digest satisfies "asset-document hashes
-- or evidence references" [MP10] and powers the public document verifier:
-- anyone holding a copy of a certificate can confirm it is byte-identical to
-- the record the platform relies on, without being given the file.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_documents` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`         CHAR(26)        NOT NULL,
  `category`          VARCHAR(64)     NOT NULL DEFAULT 'other',
  `title`             VARCHAR(255)    NOT NULL,
  `description`       TEXT            NULL,
  `storage_key`       VARCHAR(512)    NOT NULL,
  `original_filename` VARCHAR(255)    NOT NULL,
  `mime_type`         VARCHAR(128)    NOT NULL,
  `byte_size`         BIGINT UNSIGNED NOT NULL,
  `sha256`            CHAR(64)        NOT NULL,
  -- Replacing a file creates a new version; the previous one is retained for
  -- the historical-document archive. [M§14]
  `version`           INT UNSIGNED    NOT NULL DEFAULT 1,
  `supersedes_id`     BIGINT UNSIGNED NULL,
  `visibility`        ENUM('public','private','pending') NOT NULL DEFAULT 'pending',
  `publication_state` ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  -- Issue and expiry drive the expiring-document alerts. [MP11]
  `issued_on`         DATE            NULL,
  `expires_on`        DATE            NULL,
  `issuer`            VARCHAR(191)    NULL,
  `document_number`   VARCHAR(128)    NULL,
  `locale`            VARCHAR(10)     NULL,
  -- Media usage rights and alternative text are named requirements. [M§9]
  `usage_rights`      VARCHAR(255)    NULL,
  `alt_text`          VARCHAR(512)    NULL,
  `source_reference`  VARCHAR(255)    NULL,
  `uploaded_by`       BIGINT UNSIGNED NULL,
  `approved_by`       BIGINT UNSIGNED NULL,
  `approved_at`       DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_public_id` (`public_id`),
  KEY `idx_document_category_state` (`category`,`publication_state`),
  KEY `idx_document_sha256` (`sha256`),
  KEY `idx_document_expires` (`expires_on`),
  KEY `fk_document_supersedes` (`supersedes_id`),
  CONSTRAINT `fk_document_supersedes`
    FOREIGN KEY (`supersedes_id`) REFERENCES `{prefix}rc_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic attachment of a document to any registry entity.
CREATE TABLE IF NOT EXISTS `{prefix}rc_document_links` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(64)     NOT NULL,
  `entity_id`   BIGINT UNSIGNED NOT NULL,
  `role`        VARCHAR(64)     NOT NULL DEFAULT 'attachment',
  `sort_order`  INT             NOT NULL DEFAULT 0,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_link` (`document_id`,`entity_type`,`entity_id`,`role`),
  KEY `idx_doc_link_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_doc_link_document`
    FOREIGN KEY (`document_id`) REFERENCES `{prefix}rc_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Image and video gallery entries. [M§9 media gallery, videos]
CREATE TABLE IF NOT EXISTS `{prefix}rc_media_assets` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`       VARCHAR(64)     NOT NULL,
  `entity_id`         BIGINT UNSIGNED NOT NULL,
  `kind`              VARCHAR(32)     NOT NULL DEFAULT 'image',
  `attachment_id`     BIGINT UNSIGNED NULL,
  `storage_key`       VARCHAR(512)    NULL,
  `mime_type`         VARCHAR(128)    NULL,
  `width`             INT             NULL,
  `height`            INT             NULL,
  `byte_size`         BIGINT UNSIGNED NULL,
  `sha256`            CHAR(64)        NULL,
  `caption`           VARCHAR(512)    NULL,
  `alt_text`          VARCHAR(512)    NULL,
  -- Separates approved photographic evidence from illustrative or conceptual
  -- imagery. Concept art may never stand in as proof of ownership, custody,
  -- certification or reserves. [W§2, W§8]
  `is_real_photograph` TINYINT(1)     NOT NULL DEFAULT 0,
  `is_illustrative`    TINYINT(1)     NOT NULL DEFAULT 1,
  `usage_rights`      VARCHAR(255)    NULL,
  `sort_order`        INT             NOT NULL DEFAULT 0,
  `publication_state` ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_entity` (`entity_type`,`entity_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Asset programs
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_asset_programs` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_code`        VARCHAR(64)     NOT NULL,
  `slug`                VARCHAR(191)    NOT NULL,
  `name`                VARCHAR(191)    NOT NULL,
  -- The architecture must expand to future approved asset classes — precious
  -- metals, gemstones, energy, real estate, art — without a rebuild. [M§2, W§6]
  `asset_class`         VARCHAR(64)     NOT NULL DEFAULT 'industrial_metal',
  `material_category`   VARCHAR(64)     NULL,
  `material_form`       VARCHAR(64)     NULL,
  `product_name`        VARCHAR(191)    NULL,
  `summary`             TEXT            NULL,
  `description`         LONGTEXT        NULL,

  `declared_purity_pct` DECIMAL(12,6)   NULL,
  `tested_purity_pct`   DECIMAL(12,6)   NULL,
  `purity_standard`     VARCHAR(191)    NULL,

  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `availability_status` ENUM('not_offered','pre_launch','planned','available','suspended','closed') NOT NULL DEFAULT 'pre_launch',
  `verification_status` ENUM('not_verified','submitted','in_review','verified','re_verification_due','failed','not_applicable') NOT NULL DEFAULT 'not_verified',
  `custody_status`      ENUM('not_in_custody','pending_intake','in_transit','in_custody','segregated','released','not_applicable') NOT NULL DEFAULT 'not_applicable',
  `reserve_status`      ENUM('not_assessed','eligible','allocated','reconciled','exception','withdrawn') NOT NULL DEFAULT 'not_assessed',
  `tokenization_status` ENUM('pending','under_review','not_applicable','approved','published','suspended','retired') NOT NULL DEFAULT 'pending',
  `redemption_status`   ENUM('not_available','available','requested','locked','approved','burned','released','delivered','cancelled') NOT NULL DEFAULT 'not_available',

  -- Future categories must be unmistakably marked and must never be mixed into
  -- current reserve totals or active program statistics. [W§6, W§8]
  `is_future_category`  TINYINT(1)      NOT NULL DEFAULT 0,
  `jurisdiction_notes`  TEXT            NULL,

  `sort_order`          INT             NOT NULL DEFAULT 0,
  `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
  `created_by`          BIGINT UNSIGNED NULL,
  `approved_by`         BIGINT UNSIGNED NULL,
  `approved_at`         DATETIME        NULL,
  `published_at`        DATETIME        NULL,
  `archived_at`         DATETIME        NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_program_code` (`program_code`),
  UNIQUE KEY `uq_program_slug` (`slug`),
  KEY `idx_program_state` (`publication_state`,`is_future_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- English, Spanish and Italian are mandatory. [Brief: Languages]
CREATE TABLE IF NOT EXISTS `{prefix}rc_program_translations` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id`        BIGINT UNSIGNED NOT NULL,
  `locale`            VARCHAR(10)     NOT NULL,
  `name`              VARCHAR(191)    NULL,
  `summary`           TEXT            NULL,
  `description`       LONGTEXT        NULL,
  `seo_title`         VARCHAR(255)    NULL,
  `seo_description`   VARCHAR(512)    NULL,
  `publication_state` ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_program_locale` (`program_id`,`locale`),
  CONSTRAINT `fk_program_translation_program`
    FOREIGN KEY (`program_id`) REFERENCES `{prefix}rc_asset_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Typed, per-program specification fields.
--
-- Copper Powder needs particle-size distribution, morphology, apparent and tap
-- density, oxygen and moisture content, flow characteristics. [M§10]
-- Nickel Wire needs diameter, gauge, tolerance, coil length, surface finish,
-- temper, tensile strength, elongation, electrical characteristics. [M§11]
--
-- Holding these as typed rows means a third metal — or a gemstone program —
-- is a configuration change, not a schema migration, which is exactly what
-- "scalable registry" and "expandable to future approved asset programs" ask
-- for. Values are typed and indexed, so this is not an untyped blob.
CREATE TABLE IF NOT EXISTS `{prefix}rc_program_spec_fields` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id` BIGINT UNSIGNED NOT NULL,
  `group_key`  VARCHAR(64)     NOT NULL DEFAULT 'general',
  `field_key`  VARCHAR(128)    NOT NULL,
  `label`      VARCHAR(191)    NOT NULL,
  `data_type`  ENUM('string','text','integer','decimal','range','boolean','date','enum','document') NOT NULL DEFAULT 'string',
  `unit`       VARCHAR(32)     NULL,
  `enum_options` TEXT          NULL,
  `is_public`  TINYINT(1)      NOT NULL DEFAULT 1,
  `is_required` TINYINT(1)     NOT NULL DEFAULT 0,
  `sort_order` INT             NOT NULL DEFAULT 0,
  `help_text`  TEXT            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spec_field` (`program_id`,`field_key`),
  KEY `idx_spec_field_group` (`program_id`,`group_key`,`sort_order`),
  CONSTRAINT `fk_spec_field_program`
    FOREIGN KEY (`program_id`) REFERENCES `{prefix}rc_asset_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}rc_program_spec_values` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_id`           BIGINT UNSIGNED NOT NULL,
  `entity_type`        ENUM('program','lot','unit') NOT NULL,
  `entity_id`          BIGINT UNSIGNED NOT NULL,
  `value_text`         TEXT            NULL,
  `value_number`       DECIMAL(28,10)  NULL,
  `value_min`          DECIMAL(28,10)  NULL,
  `value_max`          DECIMAL(28,10)  NULL,
  `value_date`         DATE            NULL,
  `value_bool`         TINYINT(1)      NULL,
  -- Where the value came from: a certificate, a datasheet, an inspection.
  `source_reference`   VARCHAR(255)    NULL,
  `source_document_id` BIGINT UNSIGNED NULL,
  `publication_state`  ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spec_value` (`field_id`,`entity_type`,`entity_id`),
  KEY `idx_spec_value_entity` (`entity_type`,`entity_id`),
  KEY `fk_spec_value_document` (`source_document_id`),
  CONSTRAINT `fk_spec_value_field`
    FOREIGN KEY (`field_id`) REFERENCES `{prefix}rc_program_spec_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spec_value_document`
    FOREIGN KEY (`source_document_id`) REFERENCES `{prefix}rc_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Lots / batches
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_lots` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `program_id`           BIGINT UNSIGNED NOT NULL,
  -- Lot / batch identifier as marked by the producer, e.g. "03-K-07", "120/NP1".
  `lot_number`           VARCHAR(128)    NOT NULL,
  `slug`                 VARCHAR(191)    NOT NULL,

  -- Declared vs independently verified. See the header note.
  `declared_quantity`    DECIMAL(24,6)   NULL,
  `verified_quantity`    DECIMAL(24,6)   NULL,
  `quantity_unit`        VARCHAR(16)     NULL,
  `declared_gross_weight` DECIMAL(24,6)  NULL,
  `declared_net_weight`  DECIMAL(24,6)   NULL,
  `verified_net_weight`  DECIMAL(24,6)   NULL,
  `weight_unit`          VARCHAR(16)     NULL,
  -- e.g. "supplier declaration", "independent weighbridge", "custodian count"
  `quantity_source`      VARCHAR(191)    NULL,
  `unit_count`           INT UNSIGNED    NULL,

  `packaging_type`       VARCHAR(191)    NULL,
  `seal_numbers`         TEXT            NULL,
  `storage_requirements` TEXT            NULL,
  `handling_requirements` TEXT           NULL,
  `safety_documentation` TEXT            NULL,

  `producer`             VARCHAR(191)    NULL,
  `supplier`             VARCHAR(191)    NULL,
  `country_of_origin`    CHAR(2)         NULL,
  `country_of_manufacture` CHAR(2)       NULL,
  `production_date`      DATE            NULL,
  `production_method`    VARCHAR(191)    NULL,
  `acquisition_date`     DATE            NULL,

  `declared_purity_pct`  DECIMAL(12,6)   NULL,
  `tested_purity_pct`    DECIMAL(12,6)   NULL,
  `impurity_pct`         DECIMAL(12,6)   NULL,
  `purity_standard`      VARCHAR(191)    NULL,

  `publication_state`    ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `verification_status`  ENUM('not_verified','submitted','in_review','verified','re_verification_due','failed','not_applicable') NOT NULL DEFAULT 'not_verified',
  `custody_status`       ENUM('not_in_custody','pending_intake','in_transit','in_custody','segregated','released','not_applicable') NOT NULL DEFAULT 'not_applicable',
  `reserve_status`       ENUM('not_assessed','eligible','allocated','reconciled','exception','withdrawn') NOT NULL DEFAULT 'not_assessed',
  `tokenization_status`  ENUM('pending','under_review','not_applicable','approved','published','suspended','retired') NOT NULL DEFAULT 'pending',
  `redemption_status`    ENUM('not_available','available','requested','locked','approved','burned','released','delivered','cancelled') NOT NULL DEFAULT 'not_available',

  -- Encumbrance, lien and restriction status. [MP10]
  `encumbrance_status`   VARCHAR(64)     NULL,
  `encumbrance_notes`    TEXT            NULL,

  `notes`                TEXT            NULL,
  `version`              INT UNSIGNED    NOT NULL DEFAULT 1,
  `created_by`           BIGINT UNSIGNED NULL,
  `approved_by`          BIGINT UNSIGNED NULL,
  `approved_at`          DATETIME        NULL,
  `published_at`         DATETIME        NULL,
  `archived_at`          DATETIME        NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lot_number_per_program` (`program_id`,`lot_number`),
  UNIQUE KEY `uq_lot_slug` (`slug`),
  KEY `idx_lot_state` (`publication_state`),
  KEY `idx_lot_reserve` (`reserve_status`),
  CONSTRAINT `fk_lot_program`
    FOREIGN KEY (`program_id`) REFERENCES `{prefix}rc_asset_programs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Units: the individually identifiable physical object.
--
-- This is the level at which the brief requires "container-by-container
-- identification, inventory reconciliation and redemption controls" [M§10] and
-- "individual coil identification, inventory reconciliation and coil-by-coil
-- redemption" [M§11]. Redemption allocates specific units, and Proof of
-- Reserves reconciles against them.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_units` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`              BIGINT UNSIGNED NOT NULL,
  `kind`                ENUM('container','coil','bobbin','spool','ampoule','box','drum','pallet','package','other') NOT NULL DEFAULT 'container',
  `identifier`          VARCHAR(128)    NOT NULL,
  `sequence_no`         INT UNSIGNED    NULL,

  `declared_net_weight` DECIMAL(24,6)   NULL,
  `verified_net_weight` DECIMAL(24,6)   NULL,
  `gross_weight`        DECIMAL(24,6)   NULL,
  `weight_unit`         VARCHAR(16)     NULL,

  `packaging_type`      VARCHAR(191)    NULL,
  `packaging_condition` VARCHAR(191)    NULL,
  `seal_number`         VARCHAR(128)    NULL,
  `storage_location`    VARCHAR(255)    NULL,
  `storage_zone`        VARCHAR(128)    NULL,

  -- Whether this individual unit was sampled during assay. The supplied nickel
  -- certificate records sampling from bobbins 8, 10, 19 and 27, and the copper
  -- certificate from box 20; that provenance is preserved per unit rather than
  -- flattened into a note.
  `was_sampled`         TINYINT(1)      NOT NULL DEFAULT 0,
  `sampled_on`          DATE            NULL,

  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `verification_status` ENUM('not_verified','submitted','in_review','verified','re_verification_due','failed','not_applicable') NOT NULL DEFAULT 'not_verified',
  `custody_status`      ENUM('not_in_custody','pending_intake','in_transit','in_custody','segregated','released','not_applicable') NOT NULL DEFAULT 'not_applicable',
  `reserve_status`      ENUM('not_assessed','eligible','allocated','reconciled','exception','withdrawn') NOT NULL DEFAULT 'not_assessed',
  `redemption_status`   ENUM('not_available','available','requested','locked','approved','burned','released','delivered','cancelled') NOT NULL DEFAULT 'not_available',
  -- Archive / withdrawal / suspension / retirement status. [MP10]
  `lifecycle_status`    VARCHAR(64)     NULL,

  `notes`               TEXT            NULL,
  `version`             INT UNSIGNED    NOT NULL DEFAULT 1,
  `archived_at`         DATETIME        NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_identifier_per_lot` (`lot_id`,`identifier`),
  KEY `idx_unit_reserve` (`reserve_status`),
  KEY `idx_unit_redemption` (`redemption_status`),
  CONSTRAINT `fk_unit_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Laboratories, inspectors, appraisers
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_laboratories` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(191)    NOT NULL,
  `slug`                VARCHAR(191)    NOT NULL,
  `provider_type`       ENUM('laboratory','inspector','appraiser','auditor','custodian_auditor') NOT NULL DEFAULT 'laboratory',
  `address_line`        VARCHAR(255)    NULL,
  `city`                VARCHAR(128)    NULL,
  `country_code`        CHAR(2)         NULL,
  `website`             VARCHAR(255)    NULL,
  `contact_email`       VARCHAR(191)    NULL,
  `registration_number` VARCHAR(128)    NULL,
  -- Accreditation claims stay unpublished until evidence is supplied and
  -- approved: we never display an accreditation we have not been shown. [M§3]
  `accreditation_body`  VARCHAR(191)    NULL,
  `accreditation_ref`   VARCHAR(128)    NULL,
  `accreditation_verified` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_independent`      TINYINT(1)      NOT NULL DEFAULT 0,
  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `notes`               TEXT            NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_laboratory_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certificates of Analysis / assay reports. [M§10, M§11, MP10]
CREATE TABLE IF NOT EXISTS `{prefix}rc_certificates` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `laboratory_id`       BIGINT UNSIGNED NULL,
  `lot_id`              BIGINT UNSIGNED NULL,
  `certificate_number`  VARCHAR(128)    NOT NULL,
  `certificate_date`    DATE            NULL,
  `sample_date`         DATE            NULL,
  `test_method`         VARCHAR(191)    NULL,
  -- e.g. "GOST 2179-75", "TU 1793-011-50316079-2004"
  `standard_reference`  VARCHAR(191)    NULL,
  `goods_description`   VARCHAR(512)    NULL,
  `sample_description`  VARCHAR(512)    NULL,
  `sample_location`     VARCHAR(255)    NULL,
  -- Which physical units were sampled, e.g. bobbins 8/10/19/27, box 20.
  `sampled_units`       VARCHAR(512)    NULL,

  `purity_pct`          DECIMAL(12,6)   NULL,
  `impurity_pct`        DECIMAL(12,6)   NULL,
  -- Findings that are not element concentrations: isotopic composition,
  -- radioactivity statements, appearance notes.
  `additional_findings` TEXT            NULL,

  `document_id`         BIGINT UNSIGNED NULL,
  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `verification_status` ENUM('not_verified','submitted','in_review','verified','re_verification_due','failed','not_applicable') NOT NULL DEFAULT 'not_verified',
  -- Re-verification cadence drives the stale-attestation alert. [MP11]
  `valid_until`         DATE            NULL,

  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_by`         BIGINT UNSIGNED NULL,
  `approved_at`         DATETIME        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_certificate_number` (`laboratory_id`,`certificate_number`),
  KEY `idx_certificate_lot` (`lot_id`),
  KEY `idx_certificate_state` (`publication_state`),
  KEY `fk_certificate_document` (`document_id`),
  CONSTRAINT `fk_certificate_laboratory`
    FOREIGN KEY (`laboratory_id`) REFERENCES `{prefix}rc_laboratories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_certificate_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_certificate_document`
    FOREIGN KEY (`document_id`) REFERENCES `{prefix}rc_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One measured element concentration from a certificate.
--
-- Storing the assay table row by row — rather than a single typed-in purity
-- figure — lets the platform reproduce the certificate faithfully, recompute
-- impurity totals from the source data, compare lots, and show an auditor
-- exactly which number came from which report.
CREATE TABLE IF NOT EXISTS `{prefix}rc_certificate_elements` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `certificate_id` BIGINT UNSIGNED NOT NULL,
  `element`        VARCHAR(8)      NOT NULL,
  -- '<' when the laboratory reports a detection-limit bound, '=' otherwise.
  `operator`       ENUM('=','<','>','<=','>=') NOT NULL DEFAULT '=',
  `value_ppm`      DECIMAL(20,6)   NULL,
  -- Set when the element is the base material rather than an impurity.
  `is_matrix`      TINYINT(1)      NOT NULL DEFAULT 0,
  `sort_order`     INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_certificate_element` (`certificate_id`,`element`),
  CONSTRAINT `fk_certificate_element_certificate`
    FOREIGN KEY (`certificate_id`) REFERENCES `{prefix}rc_certificates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Ownership, custody, insurance, valuation
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_ownership_records` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`              BIGINT UNSIGNED NOT NULL,
  `owner_name`          VARCHAR(191)    NOT NULL,
  `owner_type`          VARCHAR(64)     NULL,
  `owner_country`       CHAR(2)         NULL,
  `acquisition_type`    ENUM('purchase','assignment','contribution','transfer','other') NULL,
  `effective_from`      DATE            NULL,
  `effective_to`        DATE            NULL,
  `title_reference`     VARCHAR(191)    NULL,
  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `verification_status` ENUM('not_verified','submitted','in_review','verified','re_verification_due','failed','not_applicable') NOT NULL DEFAULT 'not_verified',
  `notes`               TEXT            NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ownership_lot` (`lot_id`,`effective_from`),
  CONSTRAINT `fk_ownership_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}rc_custodians` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(191)    NOT NULL,
  `slug`                VARCHAR(191)    NOT NULL,
  `facility_type`       VARCHAR(64)     NULL,
  `address_line`        VARCHAR(255)    NULL,
  `city`                VARCHAR(128)    NULL,
  `country_code`        CHAR(2)         NULL,
  `registration_number` VARCHAR(128)    NULL,
  `agreement_reference` VARCHAR(191)    NULL,
  -- Custody arrangements are owner input. Until an agreement is supplied and
  -- approved, no custodian may be presented publicly. [M§1, M§3]
  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `notes`               TEXT            NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custodian_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chain-of-custody, movement and transfer history. [MP10]
CREATE TABLE IF NOT EXISTS `{prefix}rc_custody_records` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `custodian_id`          BIGINT UNSIGNED NULL,
  `lot_id`                BIGINT UNSIGNED NULL,
  `unit_id`               BIGINT UNSIGNED NULL,
  `event_type`            ENUM('intake','transfer','inspection','segregation','release','movement','audit') NOT NULL,
  `status`                ENUM('not_in_custody','pending_intake','in_transit','in_custody','segregated','released','not_applicable') NOT NULL DEFAULT 'pending_intake',
  `location_label`        VARCHAR(255)    NULL,
  `warehouse_receipt_ref` VARCHAR(191)    NULL,
  `is_segregated`         TINYINT(1)      NOT NULL DEFAULT 0,
  `occurred_at`           DATETIME        NULL,
  `recorded_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `publication_state`     ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `notes`                 TEXT            NULL,
  PRIMARY KEY (`id`),
  KEY `idx_custody_lot` (`lot_id`,`occurred_at`),
  KEY `idx_custody_unit` (`unit_id`,`occurred_at`),
  KEY `fk_custody_custodian` (`custodian_id`),
  CONSTRAINT `fk_custody_custodian`
    FOREIGN KEY (`custodian_id`) REFERENCES `{prefix}rc_custodians` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_custody_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_custody_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `{prefix}rc_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}rc_insurance_records` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`              BIGINT UNSIGNED NULL,
  `insurer_name`        VARCHAR(191)    NULL,
  `policy_number`       VARCHAR(128)    NULL,
  `coverage_type`       VARCHAR(128)    NULL,
  `coverage_amount`     DECIMAL(24,2)   NULL,
  `currency`            CHAR(3)         NULL,
  `valid_from`          DATE            NULL,
  `valid_to`            DATE            NULL,
  `publication_state`   ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `verification_status` ENUM('not_verified','submitted','in_review','verified','re_verification_due','failed','not_applicable') NOT NULL DEFAULT 'not_verified',
  `notes`               TEXT            NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_insurance_lot` (`lot_id`),
  KEY `idx_insurance_valid_to` (`valid_to`),
  CONSTRAINT `fk_insurance_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valuation history, including revaluation and impairment. [M§9, MP10]
CREATE TABLE IF NOT EXISTS `{prefix}rc_valuations` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`                BIGINT UNSIGNED NULL,
  `valuation_date`        DATE            NOT NULL,
  `valuation_type`        ENUM('valuation','revaluation','impairment') NOT NULL DEFAULT 'valuation',
  `price_per_kg`          DECIMAL(24,6)   NULL,
  `price_per_metric_ton`  DECIMAL(24,6)   NULL,
  `total_assessed_value`  DECIMAL(24,2)   NULL,
  `currency`              CHAR(3)         NOT NULL DEFAULT 'USD',
  `methodology`           VARCHAR(255)    NULL,
  `pricing_benchmark`     VARCHAR(255)    NULL,
  `valuer_name`           VARCHAR(191)    NULL,
  `valuer_is_independent` TINYINT(1)      NOT NULL DEFAULT 0,
  `document_id`           BIGINT UNSIGNED NULL,
  `publication_state`     ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  -- Drives the stale-valuation alert. [MP11]
  `valid_until`           DATE            NULL,
  `notes`                 TEXT            NULL,
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_valuation_lot` (`lot_id`,`valuation_date`),
  KEY `fk_valuation_document` (`document_id`),
  CONSTRAINT `fk_valuation_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_valuation_document`
    FOREIGN KEY (`document_id`) REFERENCES `{prefix}rc_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Material events and announcements affecting an asset. [M§9]
CREATE TABLE IF NOT EXISTS `{prefix}rc_material_events` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lot_id`            BIGINT UNSIGNED NULL,
  `unit_id`           BIGINT UNSIGNED NULL,
  `event_type`        VARCHAR(64)     NOT NULL,
  `title`             VARCHAR(255)    NOT NULL,
  `body`              LONGTEXT        NULL,
  `occurred_at`       DATETIME        NOT NULL,
  `publication_state` ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  `created_by`        BIGINT UNSIGNED NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_material_event_lot` (`lot_id`,`occurred_at`),
  KEY `fk_material_event_unit` (`unit_id`),
  CONSTRAINT `fk_material_event_lot`
    FOREIGN KEY (`lot_id`) REFERENCES `{prefix}rc_lots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_material_event_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `{prefix}rc_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Digital Asset Passport
--
-- "A persistent digital identity for every asset unit": unique asset ID,
-- specifications, certificates, provenance, verification, custody, valuation,
-- reserve status, tokenization status, blockchain references, history, QR
-- access and downloadable evidence. [W§4 page 14, M§8]
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{prefix}rc_passports` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id`           BIGINT UNSIGNED NOT NULL,
  -- Stable, public, non-enumerable identifier used in the URL and the QR code.
  `public_id`         CHAR(26)        NOT NULL,
  -- Human-readable asset ID shown on the passport, e.g. RC-CU-03K07-0020.
  `display_id`        VARCHAR(64)     NOT NULL,
  `publication_state` ENUM('draft','under_review','approved','published','unpublished','archived') NOT NULL DEFAULT 'draft',
  -- Demonstration passports carry a permanent, visible ILLUSTRATIVE marker and
  -- are excluded from every reserve and program statistic. [W§8]
  `is_illustrative`   TINYINT(1)      NOT NULL DEFAULT 1,
  -- Digest over the passport's approved evidence set, so a holder can confirm
  -- the record has not changed since it was shown to them.
  `evidence_hash`     CHAR(64)        NULL,
  `issued_at`         DATETIME        NULL,
  `version`           INT UNSIGNED    NOT NULL DEFAULT 1,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passport_unit` (`unit_id`),
  UNIQUE KEY `uq_passport_public_id` (`public_id`),
  UNIQUE KEY `uq_passport_display_id` (`display_id`),
  KEY `idx_passport_state` (`publication_state`),
  CONSTRAINT `fk_passport_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `{prefix}rc_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
