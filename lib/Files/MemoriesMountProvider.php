<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @author OpenAI
 * @license AGPL-3.0-or-later
 */

namespace OCA\Memories\Files;

use OCA\Memories\Settings\SystemConfig;
use OC\Files\Mount\MountPoint;
use OCP\Files\Config\IMountProvider;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;

final class MemoriesMountProvider implements IMountProvider
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly IRootFolder $rootFolder,
    ) {}

    /**
     * @return list<IMountPoint>
     */
    #[\Override]
    public function getMountsForUser(IUser $user, IStorageFactory $loader): array
    {
        if (!$this->systemConfig->virtualAlbumsEnabled()) {
            return [];
        }

        $mountPoint = $this->normalizeMountPoint($this->systemConfig->getVirtualAlbumsMountpoint());
        if (null === $mountPoint) {
            return [];
        }

        $fullPath = '/' . $user->getUID() . '/files' . $mountPoint;
        $relativePath = trim($mountPoint, '/');
        if ('' !== $relativePath) {
            try {
                $this->rootFolder->getUserFolder($user->getUID())->get($relativePath);
                return [];
            } catch (\Throwable) {
                // No collision at this path, continue with mount.
            }
        }

        return [
            new MountPoint(\OCA\Memories\Files\MemoriesStorage::class, $fullPath, [
                'uid' => $user->getUID(),
                'mountpoint' => $mountPoint,
            ], $loader, null, null, self::class),
        ];
    }

    public function normalizeMountPoint(string $mountPoint): ?string
    {
        $trimmed = trim((string) $mountPoint, " \t\n\r\0\x0B/");
        if ('' === $trimmed) {
            return null;
        }

        $parts = preg_split('#/+#', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $parts || [] === $parts) {
            return null;
        }

        foreach ($parts as $part) {
            if ('.' === $part || '..' === $part) {
                return null;
            }
        }

        return '/' . implode('/', $parts) . '/';
    }
}
