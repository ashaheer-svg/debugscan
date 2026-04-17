-- 013_token_workflow.sql
-- Add SMTP Settings and Token Redemption Tracking

-- 1. Add SMTP columns to system_settings
ALTER TABLE system_settings ADD COLUMN smtp_host VARCHAR(255);
ALTER TABLE system_settings ADD COLUMN smtp_port INTEGER DEFAULT 587;
ALTER TABLE system_settings ADD COLUMN smtp_user VARCHAR(255);
ALTER TABLE system_settings ADD COLUMN smtp_pass VARCHAR(255);
ALTER TABLE system_settings ADD COLUMN smtp_from VARCHAR(255);
ALTER TABLE system_settings ADD COLUMN smtp_encryption VARCHAR(10) DEFAULT 'tls';

-- 1.5 Add missing audit actions to the enum
-- Note: Postgres does not allow ALTER TYPE ... ADD VALUE within a transaction block in some versions,
-- but standard migrations usually handle these sequentially.
ALTER TYPE audit_action ADD VALUE IF NOT EXISTS 'tokens_requested';
ALTER TYPE audit_action ADD VALUE IF NOT EXISTS 'tokens_redeemed';

-- 2. Create token_redemptions table
CREATE TABLE IF NOT EXISTS token_redemptions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount INTEGER NOT NULL,
    code VARCHAR(64) UNIQUE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, redeemed, expired
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    redeemed_at TIMESTAMP WITH TIME ZONE,
    expires_at TIMESTAMP WITH TIME ZONE
);

-- 3. Create index for performance
CREATE INDEX idx_token_redemptions_code ON token_redemptions(code);
CREATE INDEX idx_token_redemptions_tenant ON token_redemptions(tenant_id);

-- Optional: Seed initial SMTP from ENV if present (placeholder)
-- UPDATE system_settings SET smtp_host = 'smtp.gmail.com', smtp_port = 587, smtp_encryption = 'tls';
