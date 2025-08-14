<?php
declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\Command;

use Flowpack\DecoupledContentStore\Core\ConcurrentBuildLockService;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\PrunnerJobId;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Neos\Flow\Cli\CommandController;

/**
 * Commands for the PREPARE stage in the pipeline. Not meant to be called manually.
 */
class ContentReleasePrepareCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;
    
    /**
     * @Flow\Inject
     * @var ConcurrentBuildLockService
     */
    protected $concurrentBuildLock;
    
    /**
     * @Flow\InjectConfiguration("maxFailedAttempts")
     */
    protected int $maxFailedAttempts;
    
    public function createContentReleaseCommand(string $contentReleaseIdentifier, string $prunnerJobId, string $workspaceName = 'live', string $accountId = 'cli'): void
    {
        
        $mayBeCreated = $this->checkIfReleaseMayBeCreated(RedisInstanceIdentifier::primary());
        
        if(!$mayBeCreated) {
            throw new \Exception('There are too many failed content releases. Please try again later.');
        }
        
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        $this->redisContentReleaseService->createContentRelease($contentReleaseIdentifier, $prunnerJobId, $logger, $workspaceName, $accountId);
    }
    
    private function checkIfReleaseMayBeCreated(RedisInstanceIdentifier $redisInstanceIdentifier): bool
    {
        $contentReleaseIds = $this->redisContentReleaseService->fetchAllReleaseIds($redisInstanceIdentifier);
        $metadata = $this->redisContentReleaseService->fetchMetadataForContentReleases($redisInstanceIdentifier, ...$contentReleaseIds);
        $failedCount = 0;
        foreach ($contentReleaseIds as $contentReleaseId){
            /** @var \Flowpack\DecoupledContentStore\PrepareContentRelease\Dto\ContentReleaseMetadata $contentReleaseMetadata */
            $contentReleaseMetadata = $metadata->getResultForContentRelease($contentReleaseId);
            if($contentReleaseMetadata->getStatus()->getStatus() === 'failed') {
                $failedCount++;
            }
        }
        
        return $failedCount < $this->maxFailedAttempts;
        
    }
    public function ensureAllOtherInProgressContentReleasesWillBeTerminatedCommand(string $contentReleaseIdentifier): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        
        $this->concurrentBuildLock->ensureAllOtherInProgressContentReleasesWillBeTerminated($contentReleaseIdentifier);
    }
    
    public function registerManualTransferJobCommand(string $contentReleaseIdentifier, string $prunnerJobId): void
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $prunnerJobId = PrunnerJobId::fromString($prunnerJobId);
        $logger = ContentReleaseLogger::fromConsoleOutput($this->output, $contentReleaseIdentifier);
        
        $this->redisContentReleaseService->registerManualTransferJob($contentReleaseIdentifier, $prunnerJobId, $logger);
    }
}
