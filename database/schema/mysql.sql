CREATE TABLE otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at BIGINT NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    UNIQUE KEY ozioma_otp_identifier_purpose_unique (identifier_hash, purpose),
    KEY ozioma_otp_expires_at_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_rate_limits (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT NOT NULL,
    KEY otp_rate_limits_expires_at_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
