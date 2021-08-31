<?php
namespace Flowpack\DecoupledContentStore\Controller;

use Flowpack\DecoupledContentStore\BackendUi\BackendUiDataService;
use Flowpack\DecoupledContentStore\ContentReleaseManager;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\ContentReleaseIdentifier;
use Flowpack\DecoupledContentStore\Core\Domain\ValueObject\RedisInstanceIdentifier;
use Flowpack\DecoupledContentStore\Core\Infrastructure\ContentReleaseLogger;
use Flowpack\DecoupledContentStore\Core\Infrastructure\RedisClientManager;
use Flowpack\DecoupledContentStore\Core\RedisKeyService;
use Flowpack\DecoupledContentStore\PrepareContentRelease\Infrastructure\RedisContentReleaseService;
use Flowpack\DecoupledContentStore\ReleaseSwitch\Infrastructure\RedisReleaseSwitchService;
use Flowpack\DecoupledContentStore\Transfer\ContentReleaseCleaner;
use Flowpack\DecoupledContentStore\Transfer\Dto\RedisKeyPostfixesForEachRelease;
use Flowpack\Prunner\PrunnerApiService;
use Neos\Flow\Annotations as Flow;
use Neos\Fusion\View\FusionView;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\Output;

class BackendController extends \Neos\Flow\Mvc\Controller\ActionController
{

    /**
     * @Flow\Inject
     * @var PrunnerApiService
     */
    protected $prunnerApiService;

    /**
     * @Flow\Inject
     * @var ContentReleaseManager
     */
    protected $contentReleaseManager;

    /**
     * @Flow\Inject
     * @var BackendUiDataService
     */
    protected $backendUiDataService;

    /**
     * @Flow\Inject
     * @var RedisClientManager
     */
    protected $redisClientManager;

    /**
     * @Flow\Inject
     * @var RedisContentReleaseService
     */
    protected $redisContentReleaseService;

    /**
     * @Flow\Inject
     * @var RedisReleaseSwitchService
     */
    protected $redisReleaseSwitchService;

    /**
     * @Flow\Inject
     * @var RedisKeyService
     */
    protected $redisKeyService;

    /**
     * @Flow\Inject
     * @var ContentReleaseCleaner
     */
    protected $contentReleaseCleaner;

    /**
     * @Flow\InjectConfiguration("redisContentStores")
     * @var array
     */
    protected $redisContentStores;

    /**
     * @Flow\InjectConfiguration("redisKeyPostfixesForEachRelease")
     * @var array
     */
    protected $redisKeyPostfixesForEachReleaseConfiguration;

    protected $defaultViewObjectName = FusionView::class;

    public function indexAction(?string $contentStore = null)
    {
        $contentStore = $contentStore ? RedisInstanceIdentifier::fromString($contentStore) : RedisInstanceIdentifier::primary();

        $this->view->assign('contentStore', $contentStore->getIdentifier());
        $this->view->assign('overviewData', $this->backendUiDataService->loadBackendOverviewData($contentStore));
        $this->view->assign('redisContentStores', array_keys($this->redisContentStores));
    }

    public function detailsAction(string $contentReleaseIdentifier, ?string $contentStore = null, ?string $detailTaskName = '')
    {
        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $contentStore = $contentStore ? RedisInstanceIdentifier::fromString($contentStore) : RedisInstanceIdentifier::primary();

        $this->view->assign('contentStore', $contentStore->getIdentifier());

        $detailsData = $this->backendUiDataService->loadDetailsData($contentReleaseIdentifier, $contentStore);
        $this->view->assign('detailsData', $detailsData);

        if ($detailTaskName !== '') {
            $this->view->assign('detailTaskName', $detailTaskName);
            $this->view->assign('jobLogs', $this->prunnerApiService->loadJobLogs($detailsData->getJob()->getId(), $detailTaskName));
        }
    }


    public function publishAllAction()
    {
        $this->contentReleaseManager->cancelAllRunningContentReleases();
        $this->contentReleaseManager->startFullContentRelease();
        $this->redirect('index');
    }

    public function removeAction(string $contentReleaseIdentifier, string $redisInstanceIdentifier)
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatusCode(405);
            return 'Method not allowed';
        }

        $contentReleaseIdentifierToRemove = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisInstanceIdentifier = $redisInstanceIdentifier ? RedisInstanceIdentifier::fromString($redisInstanceIdentifier) : RedisInstanceIdentifier::primary();

        $bufferedOutput = new BufferedOutput();
        $logger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifierToRemove);

        $this->contentReleaseCleaner->removeRelease($contentReleaseIdentifierToRemove, $redisInstanceIdentifier, $logger);

        $redis = $this->redisClientManager->getRedis($redisInstanceIdentifier);
        $redis->zRem('contentStore:registeredReleases', $contentReleaseIdentifier);

        $this->redirect('index');
    }

    public function switchAction(string $contentReleaseIdentifier, string $redisInstanceIdentifier)
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatusCode(405);
            return 'Method not allowed';
        }

        $contentReleaseIdentifier = ContentReleaseIdentifier::fromString($contentReleaseIdentifier);
        $redisInstanceIdentifier = $redisInstanceIdentifier ? RedisInstanceIdentifier::fromString($redisInstanceIdentifier) : RedisInstanceIdentifier::primary();

        $bufferedOutput = new BufferedOutput();
        $logger = ContentReleaseLogger::fromSymfonyOutput($bufferedOutput, $contentReleaseIdentifier);

        $this->redisReleaseSwitchService->switchContentRelease($redisInstanceIdentifier, $contentReleaseIdentifier, $logger);

        $this->redirect('index');
    }
}
