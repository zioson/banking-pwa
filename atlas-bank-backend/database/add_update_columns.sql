-- Migration: Add updated_at columns to tables that need PUT support
-- Run this SQL in phpMyAdmin or MySQL CLI

ALTER TABLE audit_findings ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE branches ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
