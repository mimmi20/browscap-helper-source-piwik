<?php

namespace BrowscapHelper\Source;

use Monolog\Logger;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class DirectorySource
 *
 * @author  Thomas Mueller <mimmi20@live.de>
 */
class PiwikSource implements SourceInterface
{
    /**
     * @param \Monolog\Logger                                   $logger
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int                                               $limit
     *
     * @return \Generator
     */
    public function getUserAgents(Logger $logger, OutputInterface $output, $limit = 0)
    {
        $allAgents = [];

        foreach ($this->loadFromPath($output) as $dataFile) {
            if ($limit && count($allAgents) >= $limit) {
                break;
            }

            $agentsFromFile = [];

            foreach ($dataFile as $row) {
                if (empty($row['user_agent'])) {
                    continue;
                }

                $agentsFromFile[] = $row['user_agent'];
            }

            $output->writeln(' [added ' . str_pad(number_format(count($allAgents)), 12, ' ', STR_PAD_LEFT) . ' agent' . (count($allAgents) !== 1 ? 's' : '') . ' so far]');

            $newAgents = array_diff($agentsFromFile, $allAgents);
            $allAgents = array_merge($allAgents, $newAgents);
        }

        $i = 0;
        foreach ($allAgents as $agent) {
            if ($limit && $i >= $limit) {
                return null;
            }

            ++$i;
            yield $agent;
        }
    }

    /**
     * @param \Monolog\Logger                                   $logger
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Generator
     */
    public function getTests(Logger $logger, OutputInterface $output)
    {
        $allTests = [];

        foreach ($this->loadFromPath($output) as $dataFile) {
            foreach ($dataFile as $row) {
                if (empty($row['user_agent'])) {
                    continue;
                }
                if (array_key_exists($row['user_agent'], $allTests)) {
                    continue;
                }

                $allTests[$row['user_agent']] = [];
            }
        }

        $i = 0;
        foreach ($allTests as $ua => $test) {
            ++$i;
            yield [$ua => $test];
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Generator
     */
    private function loadFromPath(OutputInterface $output = null)
    {
        $path = 'vendor/piwik/device-detector/Tests/fixtures';

        if (!file_exists($path)) {
            return;
        }

        $output->writeln('    reading path ' . $path);

        $iterator = new \RecursiveDirectoryIterator($path);

        foreach (new \RecursiveIteratorIterator($iterator) as $file) {
            /** @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $filepath = $file->getPathname();

            $output->write('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT), false);
            switch ($file->getExtension()) {
                case 'yml':
                    $data = \Spyc::YAMLLoad($filepath);

                    if (!is_array($data)) {
                        continue;
                    }

                    yield $data;
                    break;
                default:
                    // do nothing here
                    break;
            }
        }
    }
}
