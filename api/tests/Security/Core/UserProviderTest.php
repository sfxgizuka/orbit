<?php

declare(strict_types=1);

namespace App\Tests\Security\Core;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Core\UserProvider;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserProviderTest extends TestCase
{
    private ManagerRegistry|MockObject $registryMock;
    private MockObject|ObjectManager $managerMock;
    private MockObject|UserRepository $repositoryMock;
    private MockObject|User $userMock;
    private UserProvider $provider;

    protected function setUp(): void
    {
        $this->registryMock = $this->createMock(ManagerRegistry::class);
        $this->managerMock = $this->createMock(ObjectManager::class);
        $this->repositoryMock = $this->createMock(UserRepository::class);
        $this->userMock = $this->createMock(User::class);

        $this->provider = new UserProvider($this->registryMock, $this->repositoryMock);
    }

    #[Test]
    public function itDoesNotSupportAnInvalidClass(): void
    {
        $this->assertFalse($this->provider->supportsClass(\stdClass::class));
    }

    #[Test]
    public function itSupportsAValidClass(): void
    {
        $this->assertTrue($this->provider->supportsClass(User::class));
    }

    #[Test]
    public function itCannotRefreshAnInvalidObject(): void
    {
        $this->expectException(UnsupportedUserException::class);

        $objectMock = $this->createMock(UserInterface::class);
        $this->registryMock
            ->expects($this->once())
            ->method('getManagerForClass')
            ->with($objectMock::class)
            ->willReturn(null)
        ;

        $this->provider->refreshUser($objectMock);
    }

    #[Test]
    public function itRefreshesAValidObject(): void
    {
        $objectMock = $this->createMock(UserInterface::class);
        $this->registryMock
            ->expects($this->once())
            ->method('getManagerForClass')
            ->with($objectMock::class)
            ->willReturn($this->managerMock)
        ;
        $this->managerMock
            ->expects($this->once())
            ->method('refresh')
            ->with($objectMock)
            ->willReturn($this->managerMock)
        ;

        $this->assertSame($objectMock, $this->provider->refreshUser($objectMock));
    }

    #[Test]
    #[DataProvider(methodName: 'getInvalidAttributes')]
    public function itCannotLoadUserIfAttributeIsMissing(array $attributes): void
    {
        $this->expectException(UnsupportedUserException::class);

        $this->repositoryMock
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'john.doe@example.com'])
            ->willReturn($this->userMock)
        ;
        $this->repositoryMock->expects($this->never())->method('save');

        $this->provider->loadUserByIdentifier('john.doe@example.com', $attributes);
    }

    public static function getInvalidAttributes(): iterable
    {
        yield 'missing given_name' => [[]];
        yield 'missing family_name' => [[
            'given_name' => 'John',
        ]];
    }

    #[Test]
    public function itLoadsUserFromAttributes(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'john.doe@example.com'])
            ->willReturn($this->userMock)
        ;
        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->with($this->userMock)
        ;

        $this->assertSame($this->userMock, $this->provider->loadUserByIdentifier('john.doe@example.com', [
            'given_name' => 'John',
            'family_name' => 'DOE',
        ]));
    }

    #[Test]
    public function itCreatesAUserFromAttributes(): void
    {
        $expectedUser = new User();
        $expectedUser->firstName = 'John';
        $expectedUser->lastName = 'DOE';
        $expectedUser->email = 'john.doe@example.com';

        $this->repositoryMock
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'john.doe@example.com'])
            ->willReturn(null)
        ;
        $this->repositoryMock
            ->expects($this->once())
            ->method('save')
            ->with($expectedUser)
        ;

        $this->assertEquals($expectedUser, $this->provider->loadUserByIdentifier('john.doe@example.com', [
            'given_name' => 'John',
            'family_name' => 'DOE',
        ]));
    }
}
