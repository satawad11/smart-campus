-- ============================================================================
-- AI Smart Campus Security & Access Platform — PostgreSQL Least-Privilege Roles (SEC-005)
--
-- Strictly separates database privileges into least-privilege service accounts:
-- 1. campus_migration_user : DDL migrations during CI/CD release deployment
-- 2. campus_app_user       : DML operations for runtime PHP application/queue workers
-- 3. campus_backup_user    : Read-only data access for automated pg_dump backups
-- 4. campus_monitoring_user: Telemetry/metrics collection only, no table data access
--
-- No application superusers allowed.
--
-- Usage (run as the database owner/superuser, AFTER the vector and pg_trgm
-- extensions exist in the target database). Idempotent: re-running updates
-- the passwords and re-applies the grants.
--
--   psql -v ON_ERROR_STOP=1 -d <dbname> \
--        -v dbname=<dbname> \
--        -v migration_password='...' -v app_password='...' \
--        -v backup_password='...' -v monitoring_password='...' \
--        -f docs/security/LEAST-PRIVILEGE-ROLES.sql
--
-- Pilot pre-deployment fix: the previous version failed at
-- the schema REVOKE that listed DROP and TRUNCATE (not schema privileges), hardcoded
-- the database name and embedded placeholder passwords.
-- ============================================================================

-- 0. Required psql variables
\if :{?dbname}
\else
  DO $$ BEGIN RAISE EXCEPTION 'psql variable dbname is required'; END $$;
\endif
\if :{?migration_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'psql variable migration_password is required'; END $$;
\endif
\if :{?app_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'psql variable app_password is required'; END $$;
\endif
\if :{?backup_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'psql variable backup_password is required'; END $$;
\endif
\if :{?monitoring_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'psql variable monitoring_password is required'; END $$;
\endif

-- 1. Create Roles with Secure Authentication (create if missing, then set password)
SELECT format('CREATE ROLE %I WITH LOGIN', r) FROM unnest(ARRAY[
    'campus_migration_user', 'campus_app_user', 'campus_backup_user', 'campus_monitoring_user'
]) AS r WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = r) \gexec

SELECT format('ALTER ROLE %I WITH LOGIN NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD %L', r, p)
FROM (VALUES
    ('campus_migration_user', :'migration_password'),
    ('campus_app_user', :'app_password'),
    ('campus_backup_user', :'backup_password'),
    ('campus_monitoring_user', :'monitoring_password')
) AS v(r, p) \gexec

-- 2. Restrict Public Schema Defaults
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC;

-- 3. Configuration: campus_migration_user (Deployment DDL)
GRANT CONNECT ON DATABASE :"dbname" TO campus_migration_user;
GRANT USAGE, CREATE ON SCHEMA public TO campus_migration_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO campus_migration_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO campus_migration_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT ALL PRIVILEGES ON TABLES TO campus_migration_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT ALL PRIVILEGES ON SEQUENCES TO campus_migration_user;

-- 4. Configuration: campus_app_user (Runtime DML Only — No DDL)
GRANT CONNECT ON DATABASE :"dbname" TO campus_app_user;
GRANT USAGE ON SCHEMA public TO campus_app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO campus_app_user;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO campus_app_user;

-- Ensure future tables created by migration_user allow app_user DML
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO campus_app_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO campus_app_user;

-- Explicitly revoke DDL powers from app_user. CREATE is the only DDL privilege
-- on a schema; DROP/ALTER belong to table owners (campus_migration_user) and
-- TRUNCATE is a table privilege never granted to app_user above.
REVOKE CREATE ON SCHEMA public FROM campus_app_user;
REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM campus_app_user;

-- 5. Configuration: campus_backup_user (Read-Only Dump Privilege)
GRANT CONNECT ON DATABASE :"dbname" TO campus_backup_user;
GRANT USAGE ON SCHEMA public TO campus_backup_user;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO campus_backup_user;
GRANT SELECT, USAGE ON ALL SEQUENCES IN SCHEMA public TO campus_backup_user;

ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT SELECT ON TABLES TO campus_backup_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT SELECT, USAGE ON SEQUENCES TO campus_backup_user;

-- 6. Configuration: campus_monitoring_user (Telemetry Only — Zero Table Access)
GRANT CONNECT ON DATABASE :"dbname" TO campus_monitoring_user;
GRANT pg_monitor TO campus_monitoring_user;

-- Explicitly deny table data read to monitoring user
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM campus_monitoring_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    REVOKE ALL ON TABLES FROM campus_monitoring_user;

-- Verification Queries (Run as superuser to verify isolation):
-- SELECT rolname, rolsuper, rolinherit, rolcreaterole, rolcreatedb, rolcanlogin FROM pg_roles WHERE rolname LIKE 'campus_%';
-- SELECT has_schema_privilege('campus_app_user', 'public', 'CREATE');   -- expect f
-- SELECT has_table_privilege('campus_backup_user', 'users', 'INSERT');   -- expect f
