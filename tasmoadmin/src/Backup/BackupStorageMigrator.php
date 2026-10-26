<?php

namespace TasmoAdmin\Backup;

/**
 * Moves every device backup from one storage to another. All backups are
 * copied and checked first; the originals are removed only afterwards.
 */
class BackupStorageMigrator
{
    /**
     * @return int the number of backups moved
     */
    public function migrate(BackupStorage $from, BackupStorage $to): int
    {
        if ($from->getType() === $to->getType()) {
            return 0;
        }

        $copied = [];
        foreach ($from->list() as $summary) {
            $backup = $from->get($summary->id);
            if (null === $backup || null === $backup->content) {
                throw new \RuntimeException(sprintf('Backup %s could not be read; nothing was moved.', $summary->id));
            }
            $newId = $to->store($backup);
            $stored = $to->get($newId);
            if (null === $stored || $stored->content !== $backup->content) {
                foreach ($copied as [, $copiedId]) {
                    $to->delete($copiedId);
                }
                $to->delete($newId);

                throw new \RuntimeException(sprintf('Backup %s could not be verified after copying; nothing was moved.', $summary->id));
            }
            $copied[] = [$summary->id, $newId];
        }

        foreach ($copied as [$oldId]) {
            $from->delete($oldId);
        }

        return count($copied);
    }
}
