<?php

namespace OCA\CameraRawPreviews\Command;

use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Encryption\IManager;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeletePreviews extends Command
{
    /** @var string */
    protected $appName;

    /** @var IConfig */
    protected $config;

    /** @var IDBConnection */
    protected $connection;

    /** @var OutputInterface */
    protected $output;

    /** @var IManager */
    protected $encryptionManager;

    /** @var string */
    protected $instanceId;

    /** @var string */
    protected $dataDirectory;

    /**
     * @param string $appName
     * @param IConfig $config
     * @param IDBConnection $connection
     * @param IManager $encryptionManager
     */
    public function __construct(
        $appName,
        IConfig $config,
        IDBConnection $connection,
        IManager $encryptionManager
    )
    {
        parent::__construct();

        $this->appName = $appName;
        $this->config = $config;
        $this->connection = $connection;
        $this->encryptionManager = $encryptionManager;
        $this->instanceId = $this->config->getSystemValue('instanceid');
        $this->dataDirectory = $this->config->getSystemValue('datadirectory');
    }

    protected function configure()
    {
        $this
            ->setName('camerarawpreviews:delete-previews')
            ->setDescription('Delete generated previews by the CameraRawPreviews')
            ->addOption(
                'mime',
                'm',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Specify mime types to delete. Default --mime=image/x-dcraw --mime=image/x-indesign'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Needed to delete files'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->encryptionManager->isEnabled()) {
            $output->writeln('<error>This command does work with encryption.</error>');
            return 1;
        }

        $mimes = $input->getOption('mime');
        if (empty($mimes)) {
            $mimes = ['image/x-dcraw', 'image/x-indesign'];
        }

        $dryRun = !$input->getOption('force');
        $this->output = $output;
        $count = $this->work($dryRun, $mimes);

        if ($count > 1) {
            $output->writeln("<info>Deleted previews for $count file(s).</info>");
        } else if ($count === 1) {
            $output->writeln("<info>Deleted previews for $count file(s).</info>");
        } else {
            $output->writeln("<info>Nothing to delete.</info>");
        }

        return 0;
    }

    private function work(bool $dryRun, array $mimes)
    {
        $appdataFolder = 'appdata_' . $this->instanceId;
        $previewFolder = $appdataFolder . '/preview';
        $lastFolderId = null;
        $deleteCount = 0;
        $previewCount = null;

        while (true) {

            $qb = $this->connection->getQueryBuilder();
            $qb
                ->select('c2.fileid', 'c2.storage', 'c2.path')
                ->from('filecache', 'c1')
                ->from('filecache', 'c2')
                ->from('mimetypes', 'm')
                ->where($qb->expr()->in('m.mimetype', $qb->createNamedParameter($mimes, $qb::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('m.id', 'c1.mimetype'))
                ->andWhere($qb->expr()->eq('c2.name', 'c1.fileid'))
                ->andWhere($qb->expr()->like('c2.path', $qb->createNamedParameter($previewFolder . '/%', $qb::PARAM_STR)))
                ->setFirstResult($deleteCount)
                ->setMaxResults(100);


            $stmt = $qb->executeQuery();
            $previewFolders = $stmt->fetchAll();
            $stmt->closeCursor();

            if (count($previewFolders) === 0) {
                break;
            }

            foreach ($previewFolders as &$folder) {
                if ($lastFolderId === $folder['fileid']) {
                    return;
                }
                $lastFolderId = $folder['fileid'];

                $re = '/^appdata_\w+\/preview\/.+\/(\d+)$/';

                if (!preg_match($re, $folder['path'], $matches)) {
                    throw new Exception('Could not extract out file id for preview: ' . json_encode($folder));
                }
                $originalImageFileId = $matches[1];
                $qb = $this->connection->getQueryBuilder();
                $qb->select('path')
                    ->from('filecache')
                    ->where(
                        $qb->expr()->eq('fileid', $qb->createNamedParameter($originalImageFileId))
                    );
                $cursor = $qb->executeQuery();
                $originalImageFile = $cursor->fetch();
                $cursor->closeCursor();

                if ($originalImageFile === false) {
                    continue;
                }

                $deleteCount++;

                if ($dryRun) {
                    $this->output->writeln("<info>DRY RUN: Successfully deleted all previews for $originalImageFile[path]</info>");
                    continue;
                }

                if ($this->deletePreviews($folder)) {
                    $this->output->writeln("<info>Successfully deleted all previews for $originalImageFile[path]</info>");
                }
            }
        }

        return $deleteCount;
    }

    private function deletePreviews($folder): bool
    {
        $folderDirectory = "$this->dataDirectory/$folder[path]";

        if (!file_exists($folderDirectory)) {
            throw new NotFoundException("$folder[path] is not there.");
        }

        if (!is_writable($folderDirectory)) {
            $this->output->writeln("<error>folder [$folderDirectory] is not writeable.</error>");
            return false;
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('filecache')
            ->where($qb->expr()->eq('parent', $qb->createNamedParameter($folder['fileid'])))
            ->andWhere($qb->expr()->like('path', $qb->createNamedParameter("$folder[path]/%")))
            ->andWhere($qb->expr()->eq('storage', $qb->createNamedParameter($folder['storage'])));

        $cursor = $qb->executeQuery();
        $previews = $cursor->fetchAll();
        $cursor->closeCursor();
        foreach ($previews as $preview) {
            $previewRealPath = "$this->dataDirectory/$preview[path]";

            if (file_exists($previewRealPath) && !is_writable($previewRealPath)) {
                $this->output->writeln("<error>$previewRealPath is not writeable.</error>");
                return false;
            }

            $this->connection->beginTransaction();
            try {
                $qb->delete('filecache')
                    ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($preview['fileid'])));
                $qb->executeStatement();
                if (!file_exists($previewRealPath)) {
                    throw new NotFoundException("$preview[path] is not there.");
                }
                if (!unlink($previewRealPath)) {
                    throw new Exception("Could not delete $preview[path]");
                }
                $this->connection->commit();
            } catch (Exception $e) {
                $this->output->writeln('<error>' . $e->getMessage() . '</error>');
                $this->connection->rollBack();
                return false;
            }

        }

        $this->connection->beginTransaction();
        try {
            $qb->delete('filecache')
                ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($folder['fileid'])));
            $qb->executeStatement();
            if (!rmdir($folderDirectory)) {
                throw new Exception("Could not delete $folder[path]");
            }
            $this->connection->commit();
        } catch (Exception $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
            $this->connection->rollBack();
            return false;
        }

        return true;
    }
}
