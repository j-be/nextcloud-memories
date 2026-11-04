<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Files\MemoriesMountProvider;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Tests\TestCase;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorageFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

final class VirtualAlbumsConfigTest extends TestCase
{
    public function testVirtualAlbumSettingsHaveSafeDefaults(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = null) => $default);
        $config->method('getSystemValueBool')->willReturnCallback(static fn (string $key, bool $default = false) => $default);
        $config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = '') => $default);

        $systemConfig = new SystemConfig(
            $config,
            $this->createMock(IUserConfig::class),
            $this->createMock(IRequest::class),
            $this->createMock(IAppManager::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(IUserSession::class),
            $this->createMock(\OCP\Encryption\IManager::class),
        );

        self::assertFalse($systemConfig->virtualAlbumsEnabled());
        self::assertSame('/Memories/Albums/', $systemConfig->getVirtualAlbumsMountpoint());
    }

    public function testMountProviderCreatesUserScopedAlbumsMount(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $systemConfig = $this->createMock(SystemConfig::class);
        $systemConfig->method('virtualAlbumsEnabled')->willReturn(true);
        $systemConfig->method('getVirtualAlbumsMountpoint')->willReturn('/Memories/Albums/');

        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willThrowException(new NotFoundException('missing'));

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects(self::once())
            ->method('getUserFolder')
            ->with('alice')
            ->willReturn($folder);

        $provider = new MemoriesMountProvider($systemConfig, $rootFolder);
        $mounts = $provider->getMountsForUser($user, $this->createMock(IStorageFactory::class));

        self::assertCount(1, $mounts);
        self::assertSame('/alice/files/Memories/Albums/', $mounts[0]->getMountPoint());
    }
}
