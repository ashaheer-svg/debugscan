-- AI DebugScan v3 - Core Provisioning Script (SQL)

-- 1. Create System Admin
INSERT INTO users (id, email, password_hash, role, display_name, status)
VALUES ('00000000-0000-4000-a000-000000000001', 'admin@debugscan.ia', '$2y$10$Xpt.lWByfH7WJbXz5Q8.OeL2u6yX/yqB/O7zUfS1sYh1QzRzF3Meq', 'admin', 'System Administrator', 'active')
ON CONFLICT (email) DO NOTHING;

-- 2. Create Demo Tenant
INSERT INTO users (id, email, password_hash, role, display_name, status, tokens_available)
VALUES ('00000000-0000-4000-a000-000000000002', 'demo@client.ia', '$2y$10$fVfWvG8G5G5G5G5G5G5G5eL2u6yX/yqB/O7zUfS1sYh1QzRzF3Meq', 'tenant', 'Demo Organization', 'active', 500000)
ON CONFLICT (email) DO NOTHING;

-- 3. Initialize System Settings
INSERT INTO system_settings (id, groq_api_key_encrypted, level1_model, level2_model, retention_days)
VALUES (1, 'initial_setup_placeholder', 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 30)
ON CONFLICT (id) DO NOTHING;
