<?php

declare(strict_types=1);

namespace Flowpack\DecoupledContentStore\NodeRendering;

use Flowpack\DecoupledContentStore\NodeRendering\Dto\DocumentNodeCacheKey;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderingProgress;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisContentCacheReader;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisContentReleaseWriter;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingErrorManager;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingStatisticsStore;
use Neos\Flow\Annotations as Flow;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Dto\EnumeratedNode;
use Flowpack\DecoupledContentStore\NodeEnumeration\Domain\Repository\RedisEnumerationRepository;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\NodeRenderingCompletionStatus;
use Flowpack\DecoupledContentStore\NodeRendering\Dto\RenderedDocumentFromContentCache;
use Flowpack\DecoupledContentStore\NodeRendering\Infrastructure\RedisRenderingQueue;

/**
 * TODO: explain concept of Working Set
 *
 * TODO: eventually consistent - kurz könnten Links kaputt sein
 * - Page A contains link to Page B
 * - Content release starts, enumeration lists page A and B
 * - Page A is rendered and added to content release
 * - Page B is deleted by an editor -> this flushes the cache of Page A (but does not touch the in-progress content release)
 *   - -> a new do_content_release job is added to the pipeline on the WAITING slot.
 * - Page B is attempted to be rendered (because part of enumeration, although it was already deleted (or hidden ...))
 * - Rendering for Page B FAILS (as node does not exist)
 * - -> Content Release aborts with error (and does not go live)
 *
 * - the new content release starts, enumeration lists page A (B has been deleted)
 * - Page A is rendered without the link to B.
 *
 *
 * MOVE of a page... auch kein Prbolem weil sich Node Context Path ändert.
 *
 * SCHWIERIGER: URL Segment wird geändert von Node.
 * -> Eventually consistent, kurzzeitig broken link.
 * ALTERNATIVE: Logik hier im Orchestrator ändern
 *
 * @Flow\Scope("singleton")
 */
class NodeRenderOrchestrator
{

    /**
     * @Flow\Inject
     * @var RedisEnumerationRepository
     */
    protected $redisEnumerationRepository;

    /**
     * @Flow\Inject
     * @var RedisRenderingQueue
     */
    protected $redisRenderingQueue;

    /**
     * @Flow\Inject
     * @var RedisContentCacheReader
     */
    protected $redisContentCacheReader;

    /**
     * @Flow\Inject
     * @var RedisRenderingErrorManager
     */
    protected $redisRenderingErrorManager;

    /**
     * @Flow\Inject
     * @var RedisContentReleaseWriter
     */
    protected $redisContentReleaseWriter;

    /**
     * @Flow\Inject
     * @var RedisRenderingStatisticsStore
     */
    protected $redisRenderingStatisticsStore;


    public function renderContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger)
    {
        $completionStatus = $this->redisRenderingQueue->getCompletionStatus($contentReleaseIdentifier);

        if ($completionStatus !== null) {
            $contentReleaseLogger->error('Release has already completed with status ' . $completionStatus->jsonSerialize() . ', so we cannot render again.');
            return;
        }
        // Ensure we start with an empty queue here, in case this command is called multiple times.
        $this->redisRenderingQueue->flush($contentReleaseIdentifier);
        $this->redisRenderingErrorManager->flush($contentReleaseIdentifier);
        $this->redisRenderingStatisticsStore->flush($contentReleaseIdentifier);

        if ($this->redisEnumerationRepository->count($contentReleaseIdentifier) === 0) {
            $contentReleaseLogger->error('Content Enumeration is empty. This is dangerous; we never want this to go live. Exiting.');
            $this->redisRenderingQueue->setCompletionStatus($contentReleaseIdentifier, NodeRenderingCompletionStatus::failed());
            exit(1);
        }

        $currentEnumeration = $this->redisEnumerationRepository->findAll($contentReleaseIdentifier);

        $i = 0;
        do {
            $i++;
            if ($i > 10) {
                $contentReleaseLogger->error('FAILED to build a complete content release after 10 rendering attempts. Exiting.');
                $this->redisRenderingQueue->setCompletionStatus($contentReleaseIdentifier, NodeRenderingCompletionStatus::failed());
                exit(1);
            }

            $contentReleaseLogger->info('Starting iteration ' . $i);

            $nodesScheduledForRendering = $this->goTroughEnumeratedNodesFillContentReleaseAndCheckWhatStillNeedsToBeDone($currentEnumeration, $contentReleaseIdentifier, $contentReleaseLogger);

            if (count($nodesScheduledForRendering) === 0) {
                // we have NO nodes scheduled for rendering anymore, so that means we FINISHED successfully.
                $contentReleaseLogger->info('Everything rendered completely. Finishing RenderOrchestrator');
                // info to all renderers that we finished, and they should terminate themselves gracefully.
                $this->redisRenderingQueue->setCompletionStatus($contentReleaseIdentifier, NodeRenderingCompletionStatus::success());
                // Exit successfully.
                exit(0);
            }

            // at this point, we have:
            // - copied everything to the content release which was already fully rendered
            // - for everything else (stuff not rendered at all or not fully rendered), we enqueued them for rendering.
            //
            // Now, we need to wait for the rendering to complete.
            $contentReleaseLogger->info('Waiting for renderings to complete...');
            $waitTimer = 0;

            // we remember the $totalJobsCount for displaying the rendering progress
            $totalJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
            // $remainingJobsCount is needed to figure out
            $remainingJobsCount = $totalJobsCount;
            while ($this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier) > 0 || $this->redisRenderingQueue->numberOfRenderingsInProgress($contentReleaseIdentifier) > 0) {
                sleep(1);
                $waitTimer++;
                if ($waitTimer % 10 === 0) {
                    $previousRemainingJobs = $remainingJobsCount;
                    $remainingJobsCount = $this->redisRenderingQueue->numberOfQueuedJobs($contentReleaseIdentifier);
                    $jobsWorkedThroughOverLastTenSeconds = $previousRemainingJobs - $remainingJobsCount;
                    $this->redisRenderingStatisticsStore->addDataPointForRenderingsPerSecond($contentReleaseIdentifier, $jobsWorkedThroughOverLastTenSeconds / 10);
                    $this->redisRenderingStatisticsStore->updateRenderingProgress($contentReleaseIdentifier, RenderingProgress::create($remainingJobsCount, $totalJobsCount));

                    $contentReleaseLogger->debug('Waiting... ', [
                        'numberOfQueuedJobs' => $remainingJobsCount,
                        'numberOfRenderingsInProgress' => $this->redisRenderingQueue->numberOfRenderingsInProgress($contentReleaseIdentifier),
                    ]);
                }
            }

            $contentReleaseLogger->info('Rendering iteration completed. Continuing with next iteration.');
            // here, the rendering has completed. in the next iteration, we try to copy the
            // nodes which have been rendered in this iteration to the content store - so we iterate over the
            // just-rendered nodes.
            $currentEnumeration = $nodesScheduledForRendering;
        } while(!empty($currentEnumeration));
    }

    protected function goTroughEnumeratedNodesFillContentReleaseAndCheckWhatStillNeedsToBeDone(iterable $currentEnumeration, ContentReleaseIdentifier $contentReleaseIdentifier, ContentReleaseLogger $contentReleaseLogger): array
    {
        $nodesScheduledForRendering = [];
        foreach ($currentEnumeration as $enumeratedNode) {
            assert($enumeratedNode instanceof EnumeratedNode);

            $renderedDocumentFromContentCache = $this->redisContentCacheReader->tryToExtractRenderingForEnumeratedNodeFromContentCache(DocumentNodeCacheKey::fromEnumeratedNode($enumeratedNode));

            if ($renderedDocumentFromContentCache->isComplete()) {
                $contentReleaseLogger->debug('Node fully rendered, adding to content release', ['node' => $enumeratedNode]);
                // NOTE: Eventually consistent (TODO describe)
                // If wanted more fully consistent, move to bottom....
                $this->addRenderedDocumentToContentRelease($contentReleaseIdentifier, $renderedDocumentFromContentCache);
            } else {
                $contentReleaseLogger->debug('Scheduling rendering for Node, as it was not found or its content is incomplete: '. $renderedDocumentFromContentCache->getIncompleteReason(), ['node' => $enumeratedNode]);
                // the rendered document was not found, or has holes. so we need to re-render.
                $nodesScheduledForRendering[] = $enumeratedNode;
                $this->redisRenderingQueue->appendRenderingJob($contentReleaseIdentifier, $enumeratedNode);
            }
        }

        return $nodesScheduledForRendering;
    }


    protected function addRenderedDocumentToContentRelease(ContentReleaseIdentifier $contentReleaseIdentifier, RenderedDocumentFromContentCache $renderedDocumentFromContentCache)
    {
        $compressedContent = gzencode($renderedDocumentFromContentCache->getFullContent(), 9);
        $this->redisContentReleaseWriter->writeRenderedDocumentsToContentRelease($contentReleaseIdentifier, $renderedDocumentFromContentCache->getUrl(), $compressedContent);
    }

}