<?php
namespace ShortPixel\Controller\Backup;

use DateInvalidOperationException;
use ShortPixel\Model\File\DirectoryModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Backup controller for local-filesystem backup storage.
 *
 * Selected by {@see BackupController::getBackupController()} when the
 * `backupImages` setting is truthy. Implements automatic backup removal by
 * walking the plugin's backup directory tree (mirroring the `uploads/year/month`
 * structure) and deleting files or directories older than the configured
 * `autoRemoveBackupsPeriod`.
 *
 * The actual per-file backup creation, restoration, and deletion logic lives in
 * {@see \ShortPixel\Model\Backup\LocalBackupModel}; this controller is
 * responsible only for the cron / WP-CLI triggered pruning of the backup tree.
 *
 * Neither the "normal" backup mode (each file + thumbnail backed up separately)
 * nor the "Smart Backup" (`singleFileBackup`) mode change which controller is
 * selected — both go through LocalBackupController. The distinction between
 * those two modes is handled entirely in LocalBackupModel.
 *
 * @see BackupController Parent class for the factory and shared infrastructure.
 * @see \ShortPixel\Model\Backup\LocalBackupModel Per-image backup model.
 * @package ShortPixel\Controller\Backup
 */
class LocalBackupController extends BackupController
{
    //private $backupDirectory; // main backup directory location ;

    /**
     * Walk the local backup directory tree and remove files / directories
     * that fall before the configured retention period.
     *
     * The tree mirrors the uploads `year/month` structure under
     * `SHORTPIXEL_BACKUP_FOLDER`. Removal strategy:
     *   1. Files in the backup root (uploads at the WP root level) are pruned
     *      individually by `date_created_older` via checkFilesinDirectory().
     *   2. Year-level subdirectories whose name (a 4-digit year string) is
     *      strictly less than the cutoff year are deleted wholesale.
     *   3. For the cutoff year itself, month-level subdirectories older than
     *      the cutoff month are deleted via checkRemoveMonth(), and individual
     *      files in that year directory are pruned by timestamp.
     *
     * The cutoff date is computed as `now - period - 1 month` (the extra month
     * avoids accidental deletion of the boundary month due to directory-based
     * matching; see getPeriodAr()).
     *
     * @return false|void False when the period setting is invalid; void on completion.
     */
    protected function autoRemoveBackups()
    {
        /* @todo Since the backups are not stored in the database, the strategy for local backups could be as following:
            1. Determine year, month from the timestamp and search for this directory and 'older' directories.
            2. If month is WAY before the timestamp removal, dump the whole directory.
            3. The month that is IN the timestamp, check all files.
            4. Perhaps better is to have the timestamp only allow whole months / 6 months/ 1 year as params and dump dir?
            5. If with periods, should also have some compat for non-month installations (?)
        */

         $fs = \wpSPIO()->filesystem();
         $backupBaseDir = $this->getBackupBaseDirectory();
         $rootBackupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

         $backupSubdirs = $backupBaseDir->getSubDirectories();

         $period = $this->getPeriodAr();

         if (false === is_array($period))
         {

            Log::addError('Period in Remove backup came back empty', $period);
             return false;
         }

         // Check all files in the root backupdir (in case of uploads in root)
         $this->checkFilesinDirectory($rootBackupDir, $period['date']);

         // @todo Rtturned date formats are string, so === compare can't happen if other is intval. Perhaps
         foreach($backupSubdirs as $dir)
         {
            $dirName = $dir->getName();
            if (strlen($dirName) === 4 && $dirName < $period['year'])
            {
                Log::addInfo('Automatic Backup Removal, removing dir: ', $dir->getPath());
                $dir->delete();
            }
            elseif(strlen($dirName) === 4 && $period['year'] === $dirName)
            {
                $this->checkRemoveMonth($dir->getSubDirectories(), $period['month']);
                $this->checkFilesinDirectory($dir, $period['date']);
            }
         }

    }

    /**
     * Delete month-level subdirectories whose two-digit name is numerically
     * less than $month (i.e. older than the cutoff month within the same year).
     *
     * Comparison is done with the PHP `<` operator on strings; this works
     * correctly for zero-padded two-digit month strings ('01'–'12') but
     * would be unreliable for arbitrary strings.
     *
     * @param DirectoryModel[] $subdirs Month-level directory objects (names expected to be 'MM').
     * @param string           $month   Zero-padded cutoff month, e.g. '03'.
     * @return void
     */
    private function checkRemoveMonth($subdirs, $month)
    {
        foreach($subdirs as $subdir)
        {
             $name = $subdir->getName();

             // Every month number that is lower (older) than month
             if (strlen($name) == 2 && $name < $month)
             {
                $subdir->delete();
                 Log::addInfo('Automatic Backup Removal of month, removing ', $subdir->getPath());
             }

        }
    }

    /**
     * Delete all files in $directory whose creation date is older than $date.
     *
     * Delegates the date filtering to DirectoryModel::getFiles() via the
     * `date_created_older` option so that the filesystem abstraction layer
     * controls the actual comparison.
     *
     * @param DirectoryModel $directory Directory to prune.
     * @param int            $date      Unix timestamp cutoff; files older than this are deleted.
     * @return void
     */
    private function checkFilesinDirectory(DirectoryModel $directory, $date)
    {
        $files = $directory->getFiles(['date_created_older' => $date]);
        foreach($files as $fileObj)
        {
            $fileObj->delete();
            Log::addInfo('Removing file ' . $fileObj->getFullPath());
        }

    }

    /**
     * Compute the cutoff date components for automatic backup removal.
     *
     * Reads `autoRemoveBackupsPeriod` from settings and subtracts the
     * corresponding DateInterval from now. An additional 1-month subtraction
     * is applied to the year/month components (but NOT to the timestamp) so
     * that directory-based deletion does not accidentally remove the current
     * boundary month while still using an exact timestamp for per-file checks.
     *
     * Supported period values: 'month', '3month', '6month', '1year', '2year',
     * '5year'. Any other value maps to a null interval and returns null.
     *
     * @return array{month: string, year: string, date: int}|null
     *         Associative array with zero-padded month ('MM'), 4-digit year
     *         ('YYYY'), and Unix timestamp cutoff; or null for an unknown period.
     * @throws DateInvalidOperationException On DateTime arithmetic failure (PHP 8.3+).
     */
    private function getPeriodAr()
    {
        $settings = \wpSPIO()->settings();
        $removePeriod = $settings->autoRemoveBackupsPeriod;

        $dateNow = new \DateTime();

        switch($removePeriod)
        {
             case 'month':
                $interval = new \DateInterval('P1M');
             break;
             case '3month':
                $interval = new \DateInterval('P3M');
             break;
             case '6month':
                $interval = new \DateInterval('P6M');
             break;
             case '1year':
                $interval = new \DateInterval('P1Y');
             break;
             case '2year':
                $interval = new \DateInterval('P2Y');
             break;
             case '5year':
                $interval = new \DateInterval('P5Y');
             break;
             default:
                $interval = null;
             break;
        }

        if (is_null($interval))
        {
             return null;
        }

        $dateNow->sub($interval);

        $timestamp = $dateNow->getTimestamp();
        // @todo Add a sanity check here if month / year returns are reliable.

        //  To make sure backup is removed as period + 1 month, because the current month minus the interval will be between (and thus newer than) the dates and we are doing directory-based deleted. Not for the timestamp though
       // $dateMonth = clone $dateNow;
        $monthInterval = new \DateInterval('P1M');
        $dateNow->sub($monthInterval);
        $month = $dateNow->format('m');
        $year = $dateNow->format('Y');

        return ['month' => $month, 'year' => $year, 'date' => $timestamp];
    }

    /**
     * Return the uploads-relative subdirectory inside the backup root that
     * mirrors the WP uploads base path.
     *
     * The resulting DirectoryModel points to
     * `SHORTPIXEL_BACKUP_FOLDER/<uploads-relative-path>/`, which is where
     * year/month subdirectories live. This path is intentionally NOT the
     * bare `SHORTPIXEL_BACKUP_FOLDER` root (which is passed to
     * checkFilesinDirectory() separately for files uploaded to the WP root).
     *
     * @return DirectoryModel
     */
    private function getBackupBaseDirectory()
    {
        $fs = \wpSPIO()->filesystem();
        $wpUploadBase = $fs->getWPUploadBase();
        $rel = $wpUploadBase->getRelativePath();

        $backupBaseDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER . '/' . $rel);

        return $backupBaseDir;
    }

} // Class
