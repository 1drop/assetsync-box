<?php
declare(strict_types=1);
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2019 Hans Hoechtl <hhoechtl@1drop.de>
 *  All rights reserved
 ***************************************************************/
namespace Onedrop\AssetSync\Box\Source;

use DL\AssetSync\Domain\Dto\SourceFile;
use DL\AssetSync\Source\AbstractSource;
use DL\AssetSync\Synchronization\SourceFileCollection;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Files;
use Onedrop\AssetSync\Box\Api\BoxClient;

class BoxComSource extends AbstractSource
{
    /**
     * @var array
     */
    protected $mandatoryConfigurationOptions = ['folderId', 'clientId', 'clientSecret'];
    /**
     * @var string
     */
    protected $temporaryImportDirectory;
    /**
     * @var BoxClient
     * @Flow\Inject()
     */
    protected $boxClient;

    /**
     * @throws \Neos\Flow\Utility\Exception
     * @throws \Neos\Utility\Exception\FilesException
     */
    public function initialize(): void
    {
        $this->boxClient->setClientId($this->sourceOptions['clientId']);
        $this->boxClient->setClientSecret($this->sourceOptions['clientSecret']);
        $this->boxClient->setSourceIdentifier($this->getIdentifier());
        if (isset($this->sourceOptions['devToken'])) {
            $this->boxClient->setDevToken($this->sourceOptions['devToken']);
        }
        $this->temporaryImportDirectory = Files::concatenatePaths([
            $this->environment->getPathToTemporaryDirectory(),
            'Onedrop.AssetSync.Box.' . $this->getIdentifier()
        ]);
        Files::createDirectoryRecursively($this->temporaryImportDirectory);
    }

    /**
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return SourceFileCollection
     */
    public function generateSourceFileCollection(): SourceFileCollection
    {
        $sourceFileCollection = new SourceFileCollection();

        foreach ($this->boxClient->getFolderItemsRecursive((int)$this->sourceOptions['folderId']) as $file) {
            $fileTime = new \DateTime($file['modified_at']);
            $fileIdentifier = $file['id'] . '|||' . $file['name'];

            $sourceFileCollection->add(new SourceFile($fileIdentifier, $fileTime, $file['size']));
        }

        return $sourceFileCollection->filterByIdentifierPattern($this->fileIdentifierPattern);
    }

    /**
     * @param  SourceFile                                                  $sourceFile
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Neos\Cache\Exception
     * @throws \Neos\Cache\Exception\InvalidDataException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Onedrop\AssetSync\Box\Exception\MissingOAuthException
     * @return string
     */
    public function getPathToLocalFile(SourceFile $sourceFile): string
    {
        list($fileId, $fileName) = explode('|||', $sourceFile->getFileIdentifier());
        $temporaryTargetPathAndFilename = Files::concatenatePaths([$this->temporaryImportDirectory, $fileName]);
        if (file_exists($temporaryTargetPathAndFilename)) {
            return $temporaryTargetPathAndFilename;
        }
        $boxFileContentStream = $this->boxClient->getFile((int)$fileId);
        $target = fopen($temporaryTargetPathAndFilename, 'wb');
        stream_copy_to_stream($boxFileContentStream->detach(), $target);
        fclose($target);
        return $temporaryTargetPathAndFilename;
    }
}
