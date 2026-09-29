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
-- ============================================================================

-- 1. Create Roles with Secure Authentication
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'campus_migration_user') THEN
        CREATE ROLE campus_migration_user WITH LOGIN PASSWORD 'CHANGE_IN_PRODUCTION_MIGRATION_PWD';
    END IF;

    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'campus_app_user') THEN
        CREATE ROLE campus_app_user WITH LOGIN PASSWORD 'CHANGE_IN_PRODUCTION_APP_PWD';
    END IF;

    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'campus_backup_user') THEN
        CREATE ROLE campus_backup_user WITH LOGIN PASSWORD 'CHANGE_IN_PRODUCTION_BACKUP_PWD';
    END IF;

    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'campus_monitoring_user') THEN
        CREATE ROLE campus_monitoring_user WITH LOGIN PASSWORD 'CHANGE_IN_PRODUCTION_MONITOR_PWD';
    END IF;
END $$;

-- 2. Restrict Public Schema Defaults
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC;

-- 3. Configuration: campus_migration_user (Deployment DDL)
GRANT CONNECT ON DATABASE smart_campus TO campus_migration_user;
GRANT USAGE, CREATE ON SCHEMA public TO campus_migration_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO campus_migration_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO campus_migration_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT ALL PRIVILEGES ON TABLES TO campus_migration_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT ALL PRIVILEGES ON SEQUENCES TO campus_migration_user;

-- 4. Configuration: campus_app_user (Runtime DML Only — No DDL)
GRANT CONNECT ON DATABASE smart_campus TO campus_app_user;
GRANT USAGE ON SCHEMA public TO campus_app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO campus_app_user;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO campus_app_user;

-- Ensure future tables created by migration_user allow app_user DML
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO campus_app_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO campus_app_user;

-- Explicitly revoke DDL powers from app_user
REVOKE CREATE, DROP, TRUNCATE ON SCHEMA public FROM campus_app_user;

-- 5. Configuration: campus_backup_user (Read-Only Dump Privilege)
GRANT CONNECT ON DATABASE smart_campus TO campus_backup_user;
GRANT USAGE ON SCHEMA public TO campus_backup_user;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO campus_backup_user;
GRANT SELECT, USAGE ON ALL SEQUENCES IN SCHEMA public TO campus_backup_user;

ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT SELECT ON TABLES TO campus_backup_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    GRANT SELECT, USAGE ON SEQUENCES TO campus_backup_user;

-- 6. Configuration: campus_monitoring_user (Telemetry Only — Zero Table Access)
GRANT CONNECT ON DATABASE smart_campus TO campus_monitoring_user;
GRANT pg_monitor TO campus_monitoring_user;

-- Explicitly deny table data read to monitoring user
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM campus_monitoring_user;
ALTER DEFAULT PRIVILEGES FOR ROLE campus_migration_user IN SCHEMA public
    REVOKE ALL ON TABLES FROM campus_monitoring_user;

-- Verification Queries (Run as superuser to verify isolation):
-- SELECT rolname, rolsuper, rolinherit, rolcreaterole, rolcreatedb, rolcanlogin FROM pg_roles WHERE rolname LIKE 'campus_%';
