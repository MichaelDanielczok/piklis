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
        $this->assertSame('AM', Profile::initials('Anna', 'Muster', 'Anna'));
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

        $this->assertSame('auto', (new Profile($User))->getSettings()['theme']);
    }

    public function testSettingsHaveNoLanguage(): void
    {
        $User = $this->createMock(User::class);
        $User->method('getAttribute')->willReturnMap([
            [Profile::ATTRIBUTE_THEME, 'dark']
        ]);

        $this->assertArrayNotHasKey('language', (new Profile($User))->getSettings());
        $this->assertSame('dark', (new Profile($User))->getSettings()['theme']);
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

    public function testHoldVariantsDefaultToRulerAndSheet(): void
    {
        $User = $this->createMock(User::class);
        $User->method('getAttribute')->willReturnMap([
            [Profile::ATTRIBUTE_QUANTITY_VARIANT, 'wheel'],
            [Profile::ATTRIBUTE_MENU_VARIANT, null]
        ]);

        $settings = (new Profile($User))->getSettings();

        $this->assertSame('ruler', $settings['quantityVariant']);
        $this->assertSame('sheet', $settings['menuVariant']);
    }

    public function testUpdateSettingsStoresHoldVariants(): void
    {
        $User = $this->createMock(User::class);
        $stored = [];
        $User->method('setAttribute')->willReturnCallback(function (string $name, string $value) use (&$stored): void {
            $stored[$name] = $value;
        });
        $User->expects($this->once())->method('save');

        (new Profile($User))->updateSettings(['quantityVariant' => 'counter', 'menuVariant' => 'dialog']);

        $this->assertSame([
            Profile::ATTRIBUTE_QUANTITY_VARIANT => 'counter',
            Profile::ATTRIBUTE_MENU_VARIANT => 'dialog'
        ], $stored);
    }

    public function testUpdateSettingsRejectsUnknownVariant(): void
    {
        $User = $this->createMock(User::class);
        $User->expects($this->never())->method('save');

        $this->expectException(InvalidSettingException::class);

        (new Profile($User))->updateSettings(['menuVariant' => 'popover']);
    }

    public function testUpdateSettingsIgnoresLanguage(): void
    {
        $User = $this->createMock(User::class);
        $User->expects($this->never())->method('setAttribute');
        $User->expects($this->once())->method('save');

        (new Profile($User))->updateSettings(['language' => 'en']);
    }
}
