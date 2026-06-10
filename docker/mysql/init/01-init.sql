-- Initial database setup. Runs once on first container start.
-- The MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD env vars are already applied
-- by the official mysql image before this script runs, so we only need to set
-- grants and a few tuning defaults.

-- Ensure the app user has full rights on the database (and future ones).
GRANT ALL PRIVILEGES ON `${MYSQL_DATABASE}`.* TO '${MYSQL_USER}'@'%';
GRANT ALL PRIVILEGES ON `${MYSQL_DATABASE}_test`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;

-- Tighter defaults that work well with Laravel connection pools.
SET GLOBAL max_connections = 200;
SET GLOBAL innodb_flush_log_at_trx_commit = 2;
SET GLOBAL innodb_file_per_table = 1;
