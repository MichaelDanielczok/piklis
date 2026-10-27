<?php

namespace MichaelDanielczok\Piklis\Users;

use QUI;
use QUI\Interfaces\Users\User;

use function in_array;
use function is_string;
use function mb_strtoupper;
use function mb_substr;
use function trim;

/**
 * Benutzerdaten und App-Einstellungen.
 *
 * Name und Avatar werden im QUIQQER-Backend gepflegt; die App zeigt sie nur an.
 * Die Einstellungen liegen als Benutzerattribute (siehe user.xml).
 */
class Profile
{
    public const ATTRIBUTE_THEME = 'pk.theme';
    public const ATTRIBUTE_LANGUAGE = 'pk.language';

    public const THEMES = ['auto', 'light', 'dark'];
    public const LANGUAGES = ['auto', 'de', 'en'];

    public const PERMISSION_MANAGE = 'pk.manage';

    private const AVATAR_SIZE = 160;

    public function __construct(private readonly User $User)
    {
    }

    /**
     * Daten für `GET /me`.
     *
     * @return array<string, mixed>
     */
    public function toMeArray(): array
    {
        return [
            ...$this->toPublicArray(),
            'settings' => $this->getSettings(),
            'permissions' => [
                'manage' => (bool)QUI\Permissions\Permission::hasPermission(self::PERMISSION_MANAGE, $this->User)
            ]
        ];
    }

    /**
     * Daten, die andere Benutzer sehen dürfen.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $name = $this->getName();

        return [
            'id' => (string)$this->User->getUUID(),
            'name' => $name,
            'initials' => mb_strtoupper(mb_substr($name, 0, 1)),
            'avatarUrl' => $this->getAvatarUrl()
        ];
    }

    /**
     * @return array{theme: string, language: string}
     */
    public function getSettings(): array
    {
        $theme = $this->User->getAttribute(self::ATTRIBUTE_THEME);
        $language = $this->User->getAttribute(self::ATTRIBUTE_LANGUAGE);

        if (!in_array($theme, self::THEMES, true)) {
            $theme = 'auto';
        }

        if (!in_array($language, self::LANGUAGES, true)) {
            $language = 'auto';
        }

        return [
            'theme' => $theme,
            'language' => $language
        ];
    }

    /**
     * Übernimmt nur bekannte Einstellungen mit gültigen Werten und speichert den Benutzer.
     *
     * @param array<array-key, mixed> $input
     * @throws InvalidSettingException
     * @throws QUI\Exception
     */
    public function updateSettings(array $input): void
    {
        if (isset($input['theme'])) {
            if (!in_array($input['theme'], self::THEMES, true)) {
                throw new InvalidSettingException('theme');
            }

            $this->User->setAttribute(self::ATTRIBUTE_THEME, $input['theme']);
        }

        if (isset($input['language'])) {
            if (!in_array($input['language'], self::LANGUAGES, true)) {
                throw new InvalidSettingException('language');
            }

            $this->User->setAttribute(self::ATTRIBUTE_LANGUAGE, $input['language']);
        }

        $this->User->save();
    }

    /**
     * Vorname aus dem Backend, sonst Benutzername.
     */
    public function getName(): string
    {
        $firstname = $this->User->getAttribute('firstname');

        if (is_string($firstname) && trim($firstname) !== '') {
            return trim($firstname);
        }

        return $this->User->getUsername();
    }

    /**
     * Avatar aus dem Benutzerfeld „Avatar“ als quadratisches Cache-Bild, sonst null.
     * Bewusst nicht `getAvatar()`: das liefert ohne Avatar ein Platzhalterbild.
     */
    public function getAvatarUrl(): ?string
    {
        $avatar = $this->User->getAttribute('avatar');

        if (!is_string($avatar) || !QUI\Projects\Media\Utils::isMediaUrl($avatar)) {
            return null;
        }

        try {
            $Image = QUI\Projects\Media\Utils::getImageByUrl($avatar);

            return $Image->getSizeCacheUrl(self::AVATAR_SIZE, self::AVATAR_SIZE);
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            return null;
        }
    }
}
