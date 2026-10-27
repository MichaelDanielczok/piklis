<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Users;

use MichaelDanielczok\Piklis\Users\InvalidSettingException;
use MichaelDanielczok\Piklis\Users\Profile;
use PHPUnit\Framework\TestCase;
use QUI\Interfaces\Users\User;

/**
 * Einstellungen und Anzeigedaten ohne Datenbank (Benutzer als Mock).
 */
class ProfileTest extends TestCase
{
    public function testInitialsFromFirstAndLastName(): void
    {
        $this->assertSame('MD', Profile::initials('Michael', 'Danielczok', 'Michael'));
        $this->assertSame('ÖÄ', Profile::initials(' öz ', ' äb ', 'öz'));
    }

    public function testInitialsFallBackToName(): void
    {
        $this->assertSame('M', Profile::initials('Michael', '', 'Michael'));
        $this->assertSame('T', Profile::initials('', '', 'test2'));
    }

    public function testUnknownThemeReadsAsAuto(): void
    {
        $User = $this->createMock(User::class);
        $User->method('getAttribute')->willReturnMap([
            [Profile::ATTRIBUTE_THEME, 'sepia']
        ]);

        $this->assertSame(['theme' => 'auto'], (new Profile($User))->getSettings());
    }

    public function testSettingsHaveNoLanguage(): void
    {
        $User = $this->createMock(User::class);
        $User->method('getAttribute')->willReturnMap([
            [Profile::ATTRIBUTE_THEME, 'dark']
        ]);

        $this->assertSame(['theme' => 'dark'], (new Profile($User))->getSettings());
    }

    public function testUpdateSettingsStoresValidTheme(): void
    {
        $User = $this->createMock(User::class);
        $User->expects($this->once())
            ->method('setAttribute')
            ->with(Profile::ATTRIBUTE_THEME, 'light');
        $User->expects($this->once())->method('save');

        (new Profile($User))->updateSettings(['theme' => 'light']);
    }

    public function testUpdateSettingsRejectsInvalidTheme(): void
    {
        $User = $this->createMock(User::class);
        $User->expects($this->never())->method('save');

        $this->expectException(InvalidSettingException::class);

        (new Profile($User))->updateSettings(['theme' => 'sepia']);
    }

    public function testUpdateSettingsIgnoresLanguage(): void
    {
        $User = $this->createMock(User::class);
        $User->expects($this->never())->method('setAttribute');
        $User->expects($this->once())->method('save');

        (new Profile($User))->updateSettings(['language' => 'en']);
    }
}
