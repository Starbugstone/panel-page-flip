-- Add public_cover_path column to share_token table if it doesn't exist
ALTER TABLE share_token ADD COLUMN IF NOT EXISTS public_cover_path VARCHAR(255) DEFAULT NULL;
