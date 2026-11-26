<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="files-viewers-notebook-page" class="files-viewers-page<?php if ($_['embed']): ?> embed<?php endif; ?>"
	data-src="<?php p($_['ok'] ? $_['src'] : ''); ?>"
	data-msg-protected="<?php p($_['msg_protected']); ?>"
	data-msg-missing="<?php p($_['msg_missing']); ?>">
	<?php if (!$_['embed']): ?>
	<div class="files-viewers-page-head">
		<div class="files-viewers-page-actions">
			<?php if ($_['run'] !== ''): ?>
			<a class="button primary" href="<?php p($_['run']); ?>"><?php p($l->t('Run this notebook')); ?></a>
			<?php endif; ?>
			<a class="button" href="<?php p($_['src']); ?>" download="<?php p($_['name']); ?>"><?php p($l->t('Download')); ?></a>
		</div>
	</div>
	<?php endif; ?>
	<?php if ($_['ok']): ?>
	<div class="files-viewers-loading icon-loading"></div>
	<p class="files-viewers-msg" hidden></p>
	<?php else: ?>
	<p class="files-viewers-msg"><?php p($_['msg_missing']); ?></p>
	<?php endif; ?>
	<div class="files-viewers-nb"></div>
</div>
