#!/usr/bin/env bash
#
# GreenCorner — Phase 6: a full backup and restore cycle, end to end.
#
# Creates a real encrypted backup, restores it into an ISOLATED scratch
# database and an ISOLATED storage directory, verifies the restore against
# the backup's manifest, and then removes every scratch artefact.
#
# SAFETY
#
#   The project's own database is never written to. The restore tool refuses
#   to target it, and the scratch name carries the required "_scratch" suffix
#   (decision D-019). The scratch schema is created and dropped by
#   tests/phase6_scratch_setup.php, which applies the same fences.
#
#   The script creates the scratch database, so it needs an account with the
#   CREATE DATABASE privilege. The application's own account does not have
#   it, so it is taken from SCRATCH_ADMIN_USER and SCRATCH_ADMIN_PASS.
#
#   The backup directory is storage/backups/, which is outside the web root.
#   The backup tool refuses to write anywhere inside public/.
#
# Usage:
#   SCRATCH_ADMIN_USER=root bash tests/phase6_backup_cycle.sh

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP="$ROOT/.tools/php/php.exe"
PHP_INI="$ROOT/.tools/php/php.ini"

export BACKUP_ENCRYPTION_KEY='green-corner-phase6-verification-key'
export MYSQL_BIN='C:/Program Files/MySQL/MySQL Server 8.4/bin'

SCRATCH_DB='greencorner_phase6_scratch'
SCRATCH_UPLOADS="$ROOT/storage/scratch/uploads"

echo "=============================================================="
echo "1. Create the backup"
echo "=============================================================="

BACKUP_JSON=$("$PHP" -c "$PHP_INI" database/backup.php 2>&1)
echo "$BACKUP_JSON"

BACKUP_FILE=$(echo "$BACKUP_JSON" | grep -o '"file": "[^"]*"' | head -1 | sed 's/"file": "//; s/"$//')

if [ -z "$BACKUP_FILE" ]; then
    echo "The backup did not produce a file."
    exit 1
fi

echo ""
echo "Backup file: $BACKUP_FILE"

echo ""
echo "=============================================================="
echo "2. Create the isolated scratch database"
echo "=============================================================="

"$PHP" -c "$PHP_INI" tests/phase6_scratch_setup.php --scratch-db="$SCRATCH_DB" --action=create 2>&1

echo ""
echo "=============================================================="
echo "3. Restore into the isolated scratch database"
echo "=============================================================="

"$PHP" -c "$PHP_INI" database/restore.php \
    --file="$BACKUP_FILE" \
    --scratch-db="$SCRATCH_DB" \
    --scratch-uploads="$SCRATCH_UPLOADS" \
    --key="$BACKUP_ENCRYPTION_KEY" 2>&1

echo ""
echo "=============================================================="
echo "4. Clean up every scratch artefact"
echo "=============================================================="

"$PHP" -c "$PHP_INI" tests/phase6_scratch_setup.php --scratch-db="$SCRATCH_DB" --action=drop 2>&1

rm -rf "$ROOT/storage/scratch"
rm -rf "$ROOT"/storage/backups/.work-*

echo "The scratch database, the scratch storage and the work directories are removed."
