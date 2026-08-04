<?php

declare(strict_types=1);

namespace Samwilson\PiwigoCli;

use DirectoryIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class UploadCommand extends Command
{
    private SymfonyStyle $io;
    private Client $client;
    private string $apiUrl;

    public function configure()
    {
        parent::configure();
        $this->setName('upload');
        $this->setDescription('Upload photos to a Piwigo site.');
        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_REQUIRED,
            'Filesystem path to the config file.',
            dirname(__DIR__) . '/piwigo-cli.yaml'
        );
        $this->addOption(
            'tags',
            't',
            InputOption::VALUE_REQUIRED,
            'A list of coma separated tags to describe your photo. Missing tags are created automatically.'
        );
        $this->addArgument(
            'files',
            InputArgument::IS_ARRAY,
            'File or directories to upload. Anything other than JPG and PNG files will be ignored.'
        );
    }

    /** @inheritDoc */
    public function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->io = new SymfonyStyle($input, $output);

        $configFilename = $input->getOption('config');
        $config         = Yaml::parseFile($configFilename);
        if (! isset($config['api_url']) || ! isset($config['api_key']) || ! isset($config['api_secret'])) {
            $this->io->error('Config file not correct: ' . $configFilename);
            return Command::FAILURE;
        }
        $this->apiUrl = preg_replace('/ws\.php.*$/', '', $config['api_url']) . '/ws.php?format=json';

        $this->client = new Client([
            'headers' => [
                'User-Agent'   => 'samwilson/piwigo-cli',
                'X-PIWIGO-API' => sprintf('%s:%s', $config['api_key'], $config['api_secret']),
            ],
        ]);

        $inFiles = $input->getArgument('files');
        $tags    = $input->getOption('tags') ?? '';
        foreach ($inFiles as $inFile) {
            $this->io->writeln($inFile);
            if (is_file($inFile)) {
                $this->uploadOne($inFile, $tags);
            } elseif (is_dir($inFile)) {
                $this->uploadDirectory($inFile, $tags);
            } else {
                $this->io->writeln('    Skipping (not a file nor directory)');
            }
        }
        return Command::SUCCESS;
    }

    protected function uploadDirectory(string $dir, string $tags) : void
    {
        foreach (new DirectoryIterator($dir) as $file) {
            if ($file->isDot()) {
                continue;
            } elseif ($file->isDir()) {
                $this->uploadDirectory($file->getPathname(), $tags);
            } elseif ($file->isFile()) {
                $this->io->writeln($file->getPathname());
                $this->uploadOne($file->getPathname(), $tags);
            }
        }
    }

    /**
     * @param string $filePath Filesystem path to the file to upload.
     * @param string $tags     Comma-delimited list of tags.
     */
    private function uploadOne(string $filePath, string $tags)
    {
        $md5            = md5_file($filePath);
        $existsRequest  = $this->client->get($this->apiUrl . '&' . http_build_query([
            'method'      => 'pwg.images.exist',
            'md5sum_list' => $md5,
        ]));
        $existsResponse = json_decode($existsRequest->getBody()->getContents() ?? '', true);
        if (isset($existsResponse['result'][$md5])) {
            $this->io->writeln(sprintf('    File exists: ID %s', $existsResponse['result'][$md5]));
            return;
        }
        $uploadResponse = $this->client->post($this->apiUrl, [
            'multipart' => [
                [
                    'name'     => 'method',
                    'contents' => 'pwg.images.addSimple',
                ],
                [
                    'name'     => 'tags',
                    'contents' => $tags,
                ],
                [
                    'name'     => 'image',
                    'contents' => Utils::tryFopen($filePath, 'r'),
                ],
            ],
        ]);
        $uploadResult   = json_decode($uploadResponse->getBody()->getContents() ?? '', true);
        if (isset($uploadResult['result']['url'])) {
            $this->io->writeln(sprintf('    Uploaded: %s', $uploadResult['result']['url']));
        } else {
            $this->io->error("Upload error:\n" . Yaml::dump($uploadResult));
        }
    }
}
