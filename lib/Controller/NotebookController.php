<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Controller;

use OCA\FilesViewers\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\IManager as IShareManager;
use OCP\Util;

/**
 * A shared notebook, rendered: /apps/files_viewers/notebook?token=<share token>
 * [&path=<file in a shared folder>][&embed=1]. The browser fetches it from the
 * share's public WebDAV and renders it with the Viewer handler's code - nothing
 * runs, no external service. With the Containers app (user_pods): a "Run this
 * notebook" button. embed=1: just the notebook, and other sites may frame it
 * (e.g. a course page's preview).
 */
class NotebookController extends Controller {
	public function __construct(
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IAppManager $appManager,
		private IShareManager $shareManager,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function page(string $token = '', string $path = '', string $embed = ''): TemplateResponse {
		$path = trim($path, '/');
		$ok = preg_match('/^[A-Za-z0-9]{1,64}$/', $token) === 1
			&& !in_array('..', explode('/', $path), true) && !str_contains($path, "\0");
		$name = $path !== '' ? basename($path) : '';
		if ($ok && $name === '') {
			// The shared file's own name, for the title (a share held by another node
			// of a cluster may not be known here: then no name)
			try {
				$name = $this->shareManager->getShareByToken($token)->getNode()->getName();
			} catch (\Throwable) {
			}
		}
		$dav = '/public.php/dav/files/' . rawurlencode($token)
			. ($path !== '' ? '/' . implode('/', array_map('rawurlencode', explode('/', $path))) : '');
		$run = '';
		if ($ok && $this->appManager->isEnabledForAnyone('user_pods')) {
			try {
				$run = $this->urlGenerator->linkToRoute('user_pods.page.run', array_filter(
					['token' => $token, 'path' => $path, 'name' => $path === '' ? $name : '']));
			} catch (\Throwable) {
			}
		}
		Util::addScript(Application::APP_ID, 'files_viewers-notebook');
		$params = [
			'ok' => $ok,
			'name' => $name,
			// root-relative: same origin as the page, whatever host it was reached by
			'src' => $this->urlGenerator->getWebroot() . $dav,
			'run' => $run,
			'embed' => $embed === '1',
			'msg_protected' => $this->l->t('This notebook is protected: open its share link to enter the password.'),
			'msg_missing' => $this->l->t('The notebook could not be loaded: the link may have expired or been removed.'),
		];
		if ($embed !== '1') {
			// The public share page's layout: the instance's header, full width
			$response = new PublicTemplateResponse(Application::APP_ID, 'notebook', $params);
			$response->setHeaderTitle($name !== '' ? $name : $this->l->t('Notebook'));
			return $response;
		}
		// Shown in other sites' pages (a course page's preview): read-only, links only
		$response = new TemplateResponse(Application::APP_ID, 'notebook', $params, TemplateResponse::RENDER_AS_BASE);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameAncestorDomain('https:');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
