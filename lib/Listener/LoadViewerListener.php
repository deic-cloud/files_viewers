<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Listener;

use OCA\FilesViewers\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads our Viewer-handler bundle whenever the Viewer app initialises (Files
 * page and public shares). The bundle registers the handlers with OCA.Viewer.
 *
 * @template-implements IEventListener<Event>
 */
class LoadViewerListener implements IEventListener {
	public function handle(Event $event): void {
		Util::addScript(Application::APP_ID, 'files_viewers-main');
	}
}
