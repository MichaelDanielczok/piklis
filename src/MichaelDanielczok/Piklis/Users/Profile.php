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
 * Die Einstellungen liegen als Benutzerattribute (siehe user.xml). Die
 * Oberfläche ist nur Deutsch, deshalb gibt es keine Spracheinstellung.
 */
class Profile
{
    public const ATTRIBUTE_THEME = 'pk.theme';

    public const ATTRIBUTE_QUANTITY_VARIANT = 'pk.quantityVariant';

    public const ATTRIBUTE_MENU_VARIANT = 'pk.menuVariant';

    public const THEMES = ['auto', 'light', 'dark'];

    /** Testphase der Halten-Geste: Der erste Wert ist jeweils der Standard. */
    public const QUANTITY_VARIANTS = ['ruler', 'counter'];

    public const MENU_VARIANTS = ['sheet', 'dialog'];

    /** Produktpfleger: dürfen alle Produkte bearbeiten und ausblenden */
    public const PERMISSION_MANAGE_PRODUCTS = 'piklis.products.manage';

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
                'manageProducts' => $this->canManageProducts()
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
        return [
            'id' => (string)$this->User->getUUID(),
            'name' => $this->getName(),
            'initials' => $this->getInitials(),
            'avatarUrl' => $this->getAvatarUrl()
        ];
    }

    public function canManageProducts(): bool
    {
        return (bool)QUI\Permissions\Permission::hasPermission(self::PERMISSION_MANAGE_PRODUCTS, $this->User);
    }

    /**
     * @return array{theme: string, quantityVariant: string, menuVariant: string}
     */
    public function getSettings(): array
    {
        $theme = $this->User->getAttribute(self::ATTRIBUTE_THEME);

        if (!in_array($theme, self::THEMES, true)) {
            $theme = 'auto';
        }

        return [
            'theme' => $theme,
            'quantityVariant' => $this->variant(self::ATTRIBUTE_QUANTITY_VARIANT, self::QUANTITY_VARIANTS),
            'menuVariant' => $this->variant(self::ATTRIBUTE_MENU_VARIANT, self::MENU_VARIANTS)
        ];
    }

    /**
     * @param list<string> $variants
     */
    private function variant(string $attribute, array $variants): string
    {
        $value = $this->User->getAttribute($attribute);

        return in_array($value, $variants, true) ? $value : $variants[0];
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

        if (isset($input['quantityVariant'])) {
            if (!in_array($input['quantityVariant'], self::QUANTITY_VARIANTS, true)) {
                throw new InvalidSettingException('quantityVariant');
            }

            $this->User->setAttribute(self::ATTRIBUTE_QUANTITY_VARIANT, $input['quantityVariant']);
        }

        if (isset($input['menuVariant'])) {
            if (!in_array($input['menuVariant'], self::MENU_VARIANTS, true)) {
                throw new InvalidSettingException('menuVariant');
            }

            $this->User->setAttribute(self::ATTRIBUTE_MENU_VARIANT, $input['menuVariant']);
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

    public function getInitials(): string
    {
        $firstname = $this->User->getAttribute('firstname');
        $lastname = $this->User->getAttribute('lastname');

        return self::initials(
            is_string($firstname) ? $firstname : '',
            is_string($lastname) ? $lastname : '',
            $this->getName()
        );
    }

    /**
     * Anfangsbuchstaben von Vor- und Nachname, sonst erster Buchstabe des Namens.
     */
    public static function initials(string $firstname, string $lastname, string $fallbackName): string
    {
        $firstname = trim($firstname);
        $lastname = trim($lastname);

        if ($firstname !== '' && $lastname !== '') {
            return mb_strtoupper(mb_substr($firstname, 0, 1) . mb_substr($lastname, 0, 1));
        }

        return mb_strtoupper(mb_substr(trim($fallbackName), 0, 1));
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
