PRAGMA foreign_keys = ON;

CREATE TABLE otps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts INTEGER NOT NULL CHECK (max_attempts > 0),
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE (identifier_hash, purpose)
);
CREATE INDEX ozioma_otp_expires_at_index ON otps (expires_at);

CREATE TABLE otp_rate_limits (
    bucket_key CHAR(64) PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL
);
CREATE INDEX otp_rate_limits_expires_at_index ON otp_rate_limits (expires_at);
