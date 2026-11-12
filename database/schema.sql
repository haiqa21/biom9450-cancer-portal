
DROP DATABASE IF EXISTS cancerinsight;

CREATE DATABASE cancerinsight
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE cancerinsight;


-- ==========================================
-- 1. USERS TABLE
-- ==========================================

CREATE TABLE users (
    user_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50) NOT NULL UNIQUE,
    email          VARCHAR(255) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('patient', 'professional') NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ==========================================
-- 2. PATIENTS TABLE
-- ==========================================

CREATE TABLE patients (
    patient_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL UNIQUE,
    first_name   VARCHAR(100) NOT NULL,
    last_name    VARCHAR(100) NOT NULL,
    birthdate    DATE NOT NULL,
    sex          ENUM('female', 'male', 'other', 'unknown')
                 NOT NULL DEFAULT 'unknown',
    country      VARCHAR(100) NULL,

    CONSTRAINT fk_patients_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ==========================================
-- 3. PROFESSIONALS TABLE
-- ==========================================

CREATE TABLE professionals (
    professional_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL UNIQUE,
    first_name         VARCHAR(100) NOT NULL,
    last_name          VARCHAR(100) NOT NULL,
    professional_type  ENUM('researcher', 'oncologist') NOT NULL,
    institution        VARCHAR(200) NULL,
    speciality         VARCHAR(150) NULL,

    CONSTRAINT fk_professionals_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ==========================================
-- 4. CANCER TYPES TABLE
-- ==========================================

CREATE TABLE cancer_types (
    cancer_type_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cancer_name     VARCHAR(100) NOT NULL UNIQUE,
    description     TEXT NULL
) ENGINE=InnoDB;


-- ==========================================
-- 5. GENES TABLE
-- ==========================================

CREATE TABLE genes (
    gene_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gene_symbol  VARCHAR(50) NOT NULL UNIQUE,
    chromosome   VARCHAR(5) NOT NULL,

    INDEX idx_genes_chromosome (chromosome)
) ENGINE=InnoDB;


-- ==========================================
-- 6. MUTATIONS TABLE
-- ==========================================

CREATE TABLE mutations (
    mutation_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gene_id               INT UNSIGNED NOT NULL,
    genomic_position      BIGINT UNSIGNED NOT NULL,
    original_allele       VARCHAR(255) NULL,
    altered_allele        VARCHAR(255) NULL,
    mutation_type         VARCHAR(50) NOT NULL,
    clinical_significance ENUM(
        'pathogenic',
        'likely_pathogenic',
        'uncertain',
        'likely_benign',
        'benign',
        'unknown'
    ) NOT NULL DEFAULT 'unknown',
    genome_assembly       VARCHAR(20) NOT NULL DEFAULT 'GRCh38',

    UNIQUE KEY uq_mutation (
        gene_id,
        genomic_position,
        original_allele(50),
        altered_allele(50),
        genome_assembly
    ),

    INDEX idx_mutation_position (genomic_position),

    CONSTRAINT fk_mutations_gene
        FOREIGN KEY (gene_id) REFERENCES genes(gene_id)
) ENGINE=InnoDB;


-- ==========================================
-- 7. PATIENT DIAGNOSES TABLE
-- ==========================================

CREATE TABLE patient_diagnoses (
    diagnosis_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id          INT UNSIGNED NOT NULL,
    cancer_type_id      INT UNSIGNED NOT NULL,
    date_of_diagnosis   DATE NOT NULL,
    stage               ENUM('0', 'I', 'II', 'III', 'IV', 'unknown')
                        NOT NULL DEFAULT 'unknown',
    status              ENUM('active', 'remission')
                        NOT NULL DEFAULT 'active',

    CONSTRAINT fk_diag_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_diag_cancer_type
        FOREIGN KEY (cancer_type_id) REFERENCES cancer_types(cancer_type_id),

    UNIQUE KEY uq_diagnosis_patient (diagnosis_id, patient_id)
) ENGINE=InnoDB;


-- ==========================================
-- 8. SAMPLES TABLE
-- ==========================================

CREATE TABLE samples (
    sample_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sample_code    VARCHAR(50) NOT NULL UNIQUE,
    patient_id     INT UNSIGNED NOT NULL,
    diagnosis_id   INT UNSIGNED NULL,
    date_detected  DATE NULL,

    CONSTRAINT fk_samples_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_samples_diagnosis
        FOREIGN KEY (diagnosis_id) REFERENCES patient_diagnoses(diagnosis_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ==========================================
-- 9. PATIENT MUTATIONS TABLE
-- ==========================================

CREATE TABLE patient_mutations (
    sample_id         INT UNSIGNED NOT NULL,
    mutation_id       INT UNSIGNED NOT NULL,
    allele_frequency  DECIMAL(5,4) NULL,

    PRIMARY KEY (sample_id, mutation_id),

    CONSTRAINT chk_allele_frequency
        CHECK (
            allele_frequency IS NULL
            OR allele_frequency BETWEEN 0 AND 1
        ),

    CONSTRAINT fk_pm_sample
        FOREIGN KEY (sample_id) REFERENCES samples(sample_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_pm_mutation
        FOREIGN KEY (mutation_id) REFERENCES mutations(mutation_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ==========================================
-- 10. MUTATION-CANCER ASSOCIATIONS TABLE
-- ==========================================

CREATE TABLE mutation_cancer_associations (
    association_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mutation_id INT UNSIGNED NOT NULL,
    cancer_type_id INT UNSIGNED NOT NULL,
    evidence_source VARCHAR(255) NULL,
    association_strength DECIMAL(5,4) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_mutation_cancer (
        mutation_id,
        cancer_type_id
    ),

    CONSTRAINT chk_association_strength
        CHECK (
            association_strength IS NULL
            OR association_strength BETWEEN 0 AND 1
        ),

    CONSTRAINT fk_mca_mutation
        FOREIGN KEY (mutation_id) REFERENCES mutations(mutation_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_mca_cancer_type
        FOREIGN KEY (cancer_type_id) REFERENCES cancer_types(cancer_type_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ==========================================
-- 11. PREDICTIONS TABLE
-- ==========================================

CREATE TABLE predictions (
    prediction_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT UNSIGNED NULL,
    user_id        INT UNSIGNED NOT NULL,
    model_used     VARCHAR(100) NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_pred_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_pred_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;


-- ==========================================
-- 12. PREDICTION RESULTS TABLE
-- ==========================================

CREATE TABLE prediction_results (
    prediction_id           INT UNSIGNED NOT NULL,
    cancer_type_id          INT UNSIGNED NOT NULL,
    prediction_probability  DECIMAL(5,4) NOT NULL,

    PRIMARY KEY (prediction_id, cancer_type_id),

    CONSTRAINT chk_pred_probability
        CHECK (prediction_probability BETWEEN 0 AND 1),

    CONSTRAINT fk_pr_prediction
        FOREIGN KEY (prediction_id) REFERENCES predictions(prediction_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_pr_cancer_type
        FOREIGN KEY (cancer_type_id) REFERENCES cancer_types(cancer_type_id)
) ENGINE=InnoDB;


-- ==========================================
-- 13. FEEDBACK TABLE
-- ==========================================

CREATE TABLE feedback (
    feedback_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prediction_id    INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    feedback_rating  TINYINT UNSIGNED NOT NULL,
    comments         TEXT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_feedback_rating
        CHECK (feedback_rating BETWEEN 1 AND 5),

    CONSTRAINT fk_fb_prediction
        FOREIGN KEY (prediction_id) REFERENCES predictions(prediction_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_fb_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;


-- ==========================================
-- 14. AUDIT LOGS TABLE
-- ==========================================

CREATE TABLE audit_logs (
    log_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(50) NOT NULL,
    entity_type  VARCHAR(50) NULL,
    entity_id    INT UNSIGNED NULL,
    details      TEXT NULL,
    ip_address   VARCHAR(45) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_audit_user (user_id),
    INDEX idx_audit_time (created_at),

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;
