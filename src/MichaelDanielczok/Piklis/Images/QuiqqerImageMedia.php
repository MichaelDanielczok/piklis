<?php

namespace MichaelDanielczok\Piklis\Images;

use Doctrine\DBAL\ArrayParameterType;
use QUI;
use QUI\Projects\Media\Folder;
use QUI\Projects\Media\Image;
use QUI\Projects\Project;

use function array_map;
use function bin2hex;
use function chr;
use function file_put_contents;
use function is_file;
use function ord;
use function pathinfo;
use function random_bytes;
use function str_split;
use function str_starts_with;
use function unlink;
use function vsprintf;

use const PATHINFO_DIRNAME;
use const PATHINFO_FILENAME;

/**
 * Produktbilder als QUIQQER-Medien im Projekt der App (Standardprojekt):
 * Ordner „Produktbilder“, darin „Originale“. Wie die Benutzerbilder von
 * SmartPicker: Medien legt der Systembenutzer an, die Rechte prüft vorher
 * `Products\ProductImageService`.
 *
 * Dateinamen sind zufällige UUIDs (v4); jedes neue Bild bekommt einen neuen
 * Namen und damit neue Adressen, ein alter Stand im Browser-Cache stört nie.
 * Wer eine Adresse kennt, sieht das Bild ohne Anmeldung (Obsidian „Piklis
 * Entscheidungen“ › „Produktbilder“ › „Schutz“).
 */
final class QuiqqerImageMedia implements ImageMedia
{
    public const FOLDER = 'Produktbilder';
    public const ORIGINALS = 'Originale';

    private Project $Project;

    /**
     * @throws QUI\Exception
     */
    public function __construct(?Project $Project = null)
    {
        $this->Project = $Project ?? QUI::getProjectManager()->getStandard();
    }

    /**
     * @throws QUI\Exception
     */
    public function store(string $jpeg, string $kind): int
    {
        $System = QUI::getUsers()->getSystemUser();
        $Folder = $kind === self::KIND_ORIGINAL ? $this->originalsFolder() : $this->folder();

        $file = QUI::getTemp()->createFolder() . self::randomUuid() . '.jpg';
        file_put_contents($file, $jpeg);

        try {
            $Image = $Folder->uploadFile($file, Folder::FILE_OVERWRITE_NONE, $System);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (!($Image instanceof Image)) {
            throw new QUI\Exception('Uploaded product image is not an image.');
        }

        $Image->activate($System);

        return $Image->getId();
    }

    public function delete(int $id): void
    {
        $System = QUI::getUsers()->getSystemUser();

        try {
            $Image = $this->Project->getMedia()->get($id);
        } catch (QUI\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);

            return;
        }

        // nur eigene Bilder: nie ein Medium außerhalb der Produktbilder löschen
        if (!($Image instanceof Image) || !str_starts_with((string)$Image->getAttribute('file'), self::FOLDER . '/')) {
            return;
        }

        try {
            $this->deleteWebP($Image);
            $Image->deleteCache();
            $Image->deactivate($System);
            $Image->delete($System);
            $Image->destroy($System);
        } catch (QUI\Exception $Exception) {
            // das neue Bild ist dann trotzdem gesetzt; das alte bleibt liegen
            QUI\System\Log::writeException($Exception);
        }
    }

    /**
     * @throws QUI\Exception
     */
    public function files(array $ids): array
    {
        $Media = $this->Project->getMedia();
        $Query = QUI::getQueryBuilder();

        $rows = $Query
            ->select('id', 'file')
            ->from($Media->getTable())
            ->where($Query->expr()->in('id', ':ids'))
            ->andWhere('active = 1')
            ->andWhere('deleted = 0')
            ->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $files = [];

        foreach ($rows as $row) {
            $files[(int)$row['id']] = (string)$row['file'];
        }

        return $files;
    }

    public function cacheUrl(): string
    {
        return URL_DIR . $this->Project->getMedia()->getCacheDir();
    }

    /**
     * Ordner „Produktbilder“; fehlt er, wird er angelegt.
     *
     * @throws QUI\Exception
     */
    public function folder(): Folder
    {
        return self::childFolder($this->Project->getMedia()->firstChild(), self::FOLDER);
    }

    /**
     * Ordner „Produktbilder/Originale“; fehlt er, wird er angelegt.
     *
     * @throws QUI\Exception
     */
    public function originalsFolder(): Folder
    {
        return self::childFolder($this->folder(), self::ORIGINALS);
    }

    /**
     * @throws QUI\Exception
     */
    private static function childFolder(Folder $Parent, string $name): Folder
    {
        $System = QUI::getUsers()->getSystemUser();

        try {
            foreach ($Parent->getChildrenByName($name) as $Child) {
                if ($Child instanceof Folder) {
                    return $Child;
                }
            }
        } catch (QUI\Exception) {
            // noch kein Ordner (der Core wirft, wenn der Name fehlt)
        }

        $Folder = $Parent->createFolder($name, $System);
        $Folder->activate($System);

        return $Folder;
    }

    /**
     * WebP-Dateien, die `Image::deleteCache()` nicht kennt: `<name>.webp` im
     * Media-Cache (von `quiqqer/cache` beim Speichern angelegt) und neben der
     * Quelldatei in `media/sites/` (dort legt `quiqqer/cache` 3.6 beim
     * Verkleinern eine an, weil es die Quelle statt der Cache-Datei umwandelt).
     */
    private function deleteWebP(Image $Image): void
    {
        $file = (string)$Image->getAttribute('file');
        $directory = pathinfo($file, PATHINFO_DIRNAME);
        $name = ($directory === '.' ? '' : $directory . '/') . pathinfo($file, PATHINFO_FILENAME) . '.webp';

        $candidates = [
            CMS_DIR . $this->Project->getMedia()->getCacheDir() . $name,
            pathinfo($Image->getFullPath(), PATHINFO_DIRNAME) . '/' . pathinfo($file, PATHINFO_FILENAME) . '.webp'
        ];

        foreach ($candidates as $webp) {
            if (is_file($webp)) {
                unlink($webp);
            }
        }
    }

    /**
     * Zufällige UUID (Version 4) als Dateiname.
     */
    private static function randomUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
