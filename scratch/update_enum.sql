ALTER TYPE scan_job_status ADD VALUE IF NOT EXISTS 'error';
ALTER TYPE scan_job_status ADD VALUE IF NOT EXISTS 'retry';
