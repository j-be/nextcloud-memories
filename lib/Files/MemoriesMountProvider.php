<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @author OpenAI
 * @license AGPL-3.0-or-later
 */

namespace OCA\Memories\Files;

use OC\Files\Mount\MountPoint;
use OCA\Memories\Settings\SystemConfig;
use OCP\Files\Config\IMountProvider;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;
use Psr\Log\LoggerInterface;

final class MemoriesMountProvider implements IMountProvider
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<IMountPoint>
     */
    #[\Override]
    public function getMountsForUser(IUser $user, IStorageFactory $loader): array
    {
        if (!$this->systemConfig->virtualAlbumsEnabled()) {
            $this->logger->info('Virtual albums mount is disabled', [
                'app' => 'memories',
                'uid' => $user->getUID(),
            ]);

            return [];
        }

        $configuredMountPoint = $this->systemConfig->getVirtualAlbumsMountpoint();
        $mountPoint = $this->normalizeMountPoint($configuredMountPoint);
        if (null === $mountPoint) {
            $this->logger->warning('Virtual albums mountpoint is invalid', [
                'app' => 'memories',
                'uid' => $user->getUID(),
                'mountpoint' => $configuredMountPoint,
            ]);

            return [];
        }

        $fullPath = '/'.$user->getUID().'/files'.$mountPoint;
        $relativePath = trim($mountPoint, '/');
        if ('' !== $relativePath) {
            try {
                $node = $this->rootFolder->getUserFolder($user->getUID())->get($relativePath);
                if (self::class !== $node->getMountPoint()->getMountProvider()) {
                    $this->logger->warning('Virtual albums mountpoint is already occupied', [
                        'app' => 'memories',
                        'uid' => $user->getUID(),
                        'mountpoint' => $mountPoint,
                    ]);

                    return [];
                }
            } catch (\OCP\Files\NotFoundException) {
                // No collision at this path, continue with mount.
            } catch (\Throwable $e) {
                $this->logger->error('Failed to check virtual albums mountpoint availability', [
                    'app' => 'memories',
                    'uid' => $user->getUID(),
                    'mountpoint' => $mountPoint,
                    'exception' => $e,
                ]);

                return [];
            }
        }

        $this->logger->info('Registering virtual albums mount', [
            'app' => 'memories',
            'uid' => $user->getUID(),
            'mountpoint' => $mountPoint,
        ]);

        return [
            new MountPoint(\OCA\Memories\Files\MemoriesStorage::class, $fullPath, [
                'uid' => $user->getUID(),
                'mountpoint' => $mountPoint,
            ], $loader, null, null, self::class),
        ];
    }

    public function normalizeMountPoint(string $mountPoint): ?string
    {
        $trimmed = trim($mountPoint, " \t\n\r\0\x0B/");
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

        return '/'.implode('/', $parts).'/';
    }
}
