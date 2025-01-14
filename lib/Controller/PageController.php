<?php
/**
 * @author Frank de Lange
 * @copyright 2015 Frank de Lange
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Epubviewer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Files\IRootFolder;
use OCP\Share\IManager;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException;

use OCA\Epubviewer\Service\BookmarkService;
use OCA\Epubviewer\Service\MetadataService;
use OCA\Epubviewer\Service\PreferenceService;

class PageController extends Controller {

    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IRootFolder */
    private $rootFolder;
    private $shareManager;
    private $userId;
    private $bookmarkService;
    private $metadataService;
    private $preferenceService;

    /**
     * @param string $appName
     * @param IRequest $request
     * @param IURLGenerator $urlGenerator
     * @param IRootFolder $rootFolder
     * @param IManager $shareManager
     * @param string $userId
     * @param BookmarkService $bookmarkService
     * @param PreferenceService $preferenceService
     * @param MetadataService $metadataService
     */
    public function __construct(
            $appName,
            IRequest $request,
            IURLGenerator $urlGenerator,
            IRootFolder $rootFolder,
            IManager $shareManager,
            $userId,
            BookmarkService $bookmarkService,
            PreferenceService $preferenceService,
            MetadataService $metadataService) {
        parent::__construct($appName, $request);
        $this->urlGenerator = $urlGenerator;
        $this->rootFolder = $rootFolder;
        $this->shareManager = $shareManager;
        $this->userId = $userId;
        $this->bookmarkService = $bookmarkService;
        $this->metadataService = $metadataService;
        $this->preferenceService = $preferenceService;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function showReader() {
        $templates= [
            'application/epub+zip' => 'epubviewer',
            'application/x-cbr' => 'cbreader',
            'application/pdf' => 'pdfreader'
        ];

        /**
         *  $fileInfo = [
         *      fileId => null,
         *      fileName => null,
         *      fileType => null
         *  ];
         */
        $fileInfo = $this->getFileInfo($this->request->get['file']);
        $fileId = $fileInfo['fileId'];
        $type = $this->request->get["type"];
        $scope = $template = $templates[$type];

        $params = [
            'urlGenerator' => $this->urlGenerator,
            'downloadLink' => $this->request->get['file'],
            'scope' => $scope,
            'fileId' => $fileInfo['fileId'],
            'fileName' => $fileInfo['fileName'],
            'fileType' => $fileInfo['fileType'],
            'cursor' => $this->toJson($this->bookmarkService->getCursor($fileId)),
            'defaults' => $this->toJson($this->preferenceService->getDefault($scope)),
            'preferences' => $this->toJson($this->preferenceService->get($scope, $fileId)),
            'defaults' => $this->toJson($this->preferenceService->getDefault($scope)),
            'metadata' => $this->toJson($this->metadataService->get($fileId)),
            'annotations' => $this->toJson($this->bookmarkService->get($fileId))
        ];

        $policy = new ContentSecurityPolicy();
		$policy->addAllowedStyleDomain('\'self\'');
		$policy->addAllowedStyleDomain('blob:');
		$policy->addAllowedScriptDomain('\'self\'');
		$policy->addAllowedFrameDomain('\'self\'');
		$policy->addAllowedChildSrcDomain('\'self\'');
		$policy->addAllowedFontDomain('\'self\'');
		$policy->addAllowedFontDomain('data:');
		$policy->addAllowedFontDomain('blob:');
		$policy->addAllowedImageDomain('blob:');

        $response = new TemplateResponse($this->appName, $template, $params, 'blank');
		$response->setContentSecurityPolicy($policy);

        return $response;
    }

    /**
     * @brief sharing-aware file info retriever
     *
     * Work around the differences between normal and shared file access
     * (this should be abstracted away in OC/NC IMnsHO)
     *
     * @param string $path path-fragment from url
     * @return array
     * @throws NotFoundException
     */
    private function getFileInfo($path) {
        $count = 0;
        $shareToken = preg_replace("/(?:\/index\.php)?\/s\/([A-Za-z0-9]{15,32})\/download.*/", "$1", $path, 1,$count);

        if ($count === 1) {

            /* shared file or directory */
            $node = $this->shareManager->getShareByToken($shareToken)->getNode();
            $type = $node->getType();

            /* shared directory, need file path to continue, */
            if ($type == \OCP\Files\FileInfo::TYPE_FOLDER) {
                $query = [];
                parse_str(parse_url($path, PHP_URL_QUERY), $query);
                if (isset($query['path']) && isset($query['files'])) {
                    $node = $node->get($query['path'])->get($query['files']);
                } else {
                    throw new NotFoundException('Shared file path or name not set');
                }
            }
            $filePath = $node->getPath();
            $fileId = $node->getId();
        } else {
            $filePath = $path;
            $fileId = $this->rootFolder->getUserFolder($this->userId)
                ->get(preg_replace("/.*\/remote.php\/webdav(.*)/", "$1", rawurldecode($this->request->get['file'])))
                ->getFileInfo()
                ->getId();
        }

        return [
            'fileName' => pathInfo($filePath, PATHINFO_FILENAME),
            'fileType' => strtolower(pathInfo($filePath, PATHINFO_EXTENSION)),
            'fileId' => $fileId
        ];
    }

    private function toJson($value) {
        return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
    }
}
