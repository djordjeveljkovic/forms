#!/bin/sh
set -eu
ts=$(date -u +%Y%m%dT%H%M%SZ)
out=/backups/forms_${ts}.sql.gz
mysqldump --single-transaction --routines --triggers \
  -h "${MYSQL_HOST}" -u "${MYSQL_USER}" \
  -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" | gzip -9 > "${out}"
echo "Backup written to ${out} ($(stat -c%s "${out}") bytes)"
find /backups -type f -mtime +"${BACKUP_RETENTION_DAYS:-14}" -delete
