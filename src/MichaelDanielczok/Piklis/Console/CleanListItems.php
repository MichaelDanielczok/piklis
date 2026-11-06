<?php

namespace MichaelDanielczok\Piklis\Console;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Images\QuiqqerImageMedia;
use MichaelDanielczok\Piklis\Products\ListItemCleanup;
use MichaelDanielczok\Piklis\Products\ProductRepository;
use QUI;

/**
 * Listenartikel aufräumen (`Products\ListItemCleanup`): täglich per Cron, von
 * Hand mit
 *
 * ./console pk:clean-list-items
 */
class CleanListItems extends QUI\System\Console\Tool
{
    public function __construct()
    {
        $this->setName('pk:clean-list-items')
            ->setDescription('Piklis: remove old images and unused rows of list items');
    }

    /**
     * @throws DBALException
     * @throws QUI\Exception
     */
    public function execute(): void
    {
        $this->writeLn(self::summary(self::clean()), 'green');
        $this->resetColor();
        $this->writeLn();
    }

    /**
     * Cron von `quiqqer/cron` (cron.xml).
     *
     * @param array<string, mixed> $params
     * @throws DBALException
     * @throws QUI\Exception
     */
    public static function cron(array $params, mixed $CronManager): void
    {
        QUI\System\Log::addInfo(self::summary(self::clean()));
    }

    /**
     * @return array{images: int, rows: int}
     * @throws DBALException
     * @throws QUI\Exception
     */
    private static function clean(): array
    {
        return (new ListItemCleanup(new ProductRepository(), new QuiqqerImageMedia()))->run();
    }

    /**
     * @param array{images: int, rows: int} $result
     */
    private static function summary(array $result): string
    {
        return 'Piklis list items: ' . $result['images'] . ' images and ' . $result['rows'] . ' rows removed';
    }
}
