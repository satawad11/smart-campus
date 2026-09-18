-- FND-006 — deterministic PostgreSQL extension setup.
--
-- Executed by the postgres image on first initialization of an empty data
-- directory (files in /docker-entrypoint-initdb.d/ run in name order). Enables
-- the extensions the locked data stack requires:
--   * vector   (pgvector)  — face embedding storage/search (Module 03)
--   * pg_trgm              — trigram search (e.g. Thai name search, Module 02)
--
-- Idempotent: safe to re-run. No business tables are created here (out of scope).

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
