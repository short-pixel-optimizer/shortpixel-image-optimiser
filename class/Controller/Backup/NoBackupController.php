<?php
namespace ShortPixel\Controller\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Backup controller active when the `backupImages` setting is false.
 *
 * Selected by {@see BackupController::getBackupController()} when the user
 * has disabled image backups. Extends {@see LocalBackupController} without
 * adding any behaviour — no methods are overridden.
 *
 * The practical effect is:
 *   - No new backup files are created during optimization (because
 *     {@see \ShortPixel\Model\Backup\LocalBackupModel} is still the concrete
 *     model used by the shared {@see BackupController::getModelById()}, but
 *     the optimizer skips the createBackupFile() call when backups are off).
 *   - Existing backups remain queryable and restorable, because the same
 *     LocalBackupModel is still returned for any attachment that has
 *     previously backed-up files on disk.
 *   - The auto-removal cron / WP-CLI paths (cronRemoveBackups /
 *     cliRemoveBackups) are inherited from BackupController but will
 *     short-circuit in checkRemoveBackups() because `backupImages` is off,
 *     so autoRemoveBackups() is never actually reached.
 *
 * The unused $backupDirectory property is a remnant of an earlier design;
 * it is not read or written anywhere in this class.
 *
 * @see BackupController::getBackupController() Factory / selection logic.
 * @see LocalBackupController Active controller when backups are enabled.
 * @package ShortPixel\Controller\Backup
 */
class NoBackupController extends LocalBackupController
{
    /** @var mixed Unused remnant of an earlier design; not read or written. */
    private $backupDirectory; // main backup directory location ;


}
