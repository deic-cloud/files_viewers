<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Listener;

use OCA\FilesViewers\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/**
 * Loads our Viewer-handler bundle whenever the Viewer app initialises (Files
 * page and public shares). The bundle registers the handlers with OCA.Viewer.
 *
 * For a logged-in user who has the Containers app (user_pods), it also passes
 * that app's address, so the notebook viewer can offer "Open in Jupyter". The
 * Containers app picks the image; without it, no button.
 *
 * @template-implements IEventListener<Event>
 */
class LoadViewerListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function handle(Event $event): void {
		Util::addScript(Application::APP_ID, 'files_viewers-main');
		$user = $this->userSession->getUser();
		if ($user !== null && $this->appManager->isEnabledForUser('user_pods', $user)) {
			try {
				$this->initialState->provideInitialState('notebook_launcher',
					$this->urlGenerator->linkToRoute('user_pods.page.index'));
			} catch (\Throwable) {
				// route unknown (older user_pods) — no button
			}
		}
	}
}
