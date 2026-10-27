<?php

namespace MichaelDanielczok\Piklis\Console;

use QUI;

/**
 * Beispiel für Hintergrundarbeit: Konsole und Cron rufen denselben Code auf.
 * Lange Läufe gehören in einen Konsolenbefehl; ein App-Knopf startet ihn als
 * eigenen Prozess (siehe docs/architecture.md, „Hintergrundarbeit“).
 *
 * ./console pk:hello
 */
class Hello extends QUI\System\Console\Tool
{
    public function __construct()
    {
        $this->setName('pk:hello')
            ->setDescription('Piklis: example console command');
    }

    public function execute(): void
    {
        $this->writeLn(self::greeting(), 'green');
        $this->resetColor();
        $this->writeLn();
    }

    /**
     * Cron von `quiqqer/cron` (cron.xml).
     *
     * @param array<string, mixed> $params
     */
    public static function cron(array $params, mixed $CronManager): void
    {
        QUI\System\Log::addInfo(self::greeting());
    }

    private static function greeting(): string
    {
        return 'Piklis: hello from ' . QUI::conf('globals', 'host');
    }
}
