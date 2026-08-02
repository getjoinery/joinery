<?php
/**
 * Option lists for core settings whose choices are discovered rather than fixed.
 *
 * A declaration in settings.json names one of these as `options_from`. Anything
 * whose choices can be written down belongs in the manifest as a literal
 * `options` map; this file is only for lists that come from the filesystem, the
 * plugin registry or the database, and therefore differ per deployment.
 *
 * Every method returns a value => label map, the shape FormWriter wants, and
 * returns an empty array rather than throwing when the thing it reads is
 * absent — a settings page has to render even on a half-configured install.
 *
 * @version 1.0
 */
class CoreSettingOptions {

	/**
	 * Directories under the base path — the candidates for which site this
	 * deployment runs. A configured folder that has gone missing is kept in the
	 * list, marked, so it is visible rather than silently swapped for another.
	 */
	public static function siteFolders(): array {
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

		$settings  = Globalvars::get_instance();
		$base_path = $settings->get_setting('baseDir');
		$current   = $settings->get_setting('site_template');

		$options = array();
		if ($base_path && is_dir($base_path)) {
			foreach (LibraryFunctions::list_directories_in_directory($base_path, 'filename') as $dir) {
				if (substr($dir, 0, 1) === '.' || $dir === 'lost+found') continue;
				$options[$dir] = $dir;
			}
		}
		if ($current && !isset($options[$current])) {
			$options[$current] = $current . ' (missing)';
		}
		return $options;
	}

	/**
	 * Configured backup targets, for choosing where scheduled backups go.
	 *
	 * Keyed by target id as a STRING, because that is what comes back in the
	 * POST and what is compared against the stored setting — an int key here
	 * would match neither, and the select would quietly show the wrong target
	 * as selected.
	 *
	 * Disabled targets are listed and marked rather than hidden: a schedule
	 * already pointing at one must show what it points at, not appear unset.
	 */
	public static function backupTargets(): array {
		$options = array('0' => '— none —');
		try {
			require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
			$targets = new MultiBackupTarget(array('deleted' => false), array('bkt_name' => 'ASC'));
			$targets->load();
			foreach ($targets as $t) {
				$label = (string)$t->get('bkt_name');
				if (!$t->get('bkt_enabled')) { $label .= ' (disabled)'; }
				$options[(string)(int)$t->key] = $label;
			}
		} catch (\Throwable $e) {
			// A settings page has to render on a half-configured install.
			error_log('CoreSettingOptions::backupTargets failed: ' . $e->getMessage());
		}
		return $options;
	}

	/** Themes present on disk, by directory name. */
	public static function themes(): array {
		$options = array();
		foreach (ThemeHelper::getAvailableThemes() as $name => $theme) {
			$options[$name] = $theme->get('display_name', $name);
		}
		return $options;
	}

	/** Plugins that could supply the user interface when the theme is `plugin`. */
	public static function themePlugins(): array {
		$options = array();
		foreach (PluginHelper::getAvailablePlugins() as $name => $plugin) {
			$options[$name] = $plugin->getPluginName();
		}
		return $options;
	}

	/** Every timezone, in the grouped form the platform displays elsewhere. */
	public static function timezones(): array {
		require_once(PathHelper::getIncludePath('data/address_class.php'));
		return Address::get_timezone_drop_array();
	}

	/** Published pages, plus the blog index, as homepage candidates. */
	public static function homepageTargets(): array {
		require_once(PathHelper::getIncludePath('data/pages_class.php'));

		$pages = new MultiPage(array('deleted' => false, 'published' => true), NULL, NULL, NULL);
		$pages->load();

		$options = array();
		foreach ($pages->get_dropdown_array_link() as $url => $label) {
			$options[$url] = 'Page - ' . $label;
		}
		$options['/blog'] = 'Blog homepage';
		return $options;
	}

	/** Sending services, discovered from the provider classes. */
	public static function emailServices(): array {
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		return EmailSender::getAvailableServices();
	}

	/** Mailing list services, discovered from the provider classes. */
	public static function mailingListServices(): array {
		require_once(PathHelper::getIncludePath('includes/MailingListService.php'));
		return MailingListService::getAvailableServices();
	}

	/** Mailing lists, with the everyone option the sending paths understand. */
	public static function mailingLists(): array {
		require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

		$lists = new MultiMailingList(array('deleted' => false), NULL, NULL, NULL);
		$lists->load();

		return array('all' => 'All Lists') + $lists->get_dropdown_array();
	}

	public static function outerTemplates(): array {
		return self::templates(EmailTemplateStore::TEMPLATE_TYPE_OUTER, array(
			'default_email_template', 'bulk_outer_template',
			'group_email_outer_template', 'event_email_outer_template',
		));
	}

	public static function innerTemplates(): array {
		return self::templates(EmailTemplateStore::TEMPLATE_TYPE_INNER, array(
			'individual_email_inner_template', 'group_email_inner_template',
			'event_email_inner_template',
		));
	}

	public static function footerTemplates(): array {
		return self::templates(EmailTemplateStore::TEMPLATE_TYPE_FOOTER, array(
			'bulk_footer', 'group_email_footer_template', 'event_email_footer_template',
		));
	}

	/**
	 * Connected mailboxes that can be sent through. Each carries its address,
	 * because the account name alone does not say what a recipient will see in
	 * the From line.
	 *
	 * The model lives in the mailbox plugin, so it is loaded on demand: an
	 * install without that plugin gets an empty list rather than a fatal on a
	 * settings page.
	 */
	public static function connectedAccounts(): array {
		if (!class_exists('InboundImapAccount')) {
			$path = PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php');
			if (!is_file($path)) return array();
			require_once($path);
		}

		$options = array();
		try {
			$accounts = new MultiInboundImapAccount(array('deleted' => false));
			$accounts->load();
			foreach ($accounts as $account) {
				$label    = $account->get('iia_label') ?: $account->get('iia_username');
				$username = $account->get('iia_username');
				if ($username && $username !== $label) {
					$label .= ' (' . $username . ')';
				}
				$options[(string)$account->key] = $label;
			}
		} catch (Throwable $e) {
			error_log('[CoreSettingOptions] Could not list connected accounts: ' . $e->getMessage());
		}
		return $options;
	}

	// ── internals ────────────────────────────────────────────────────────────

	/**
	 * Templates of one kind, keyed by name.
	 *
	 * The name is what gets stored, because the name is what every consumer
	 * looks a template up by — EmailTemplate filters on emt_name. A stored
	 * value that is not a template name is kept in the list and labelled, so a
	 * wrong value stays visible and survives a save instead of being quietly
	 * swapped for whichever template happened to sort first.
	 *
	 * @param string[] $used_by Settings of this kind, so their stored values
	 *                          can be checked against the real names.
	 */
	private static function templates(string $type, array $used_by = array()): array {
		require_once(PathHelper::getIncludePath('data/email_templates_class.php'));

		$templates = new MultiEmailTemplateStore(array('template_type' => $type), NULL, NULL, NULL);
		$templates->load();

		$options = array();
		foreach ($templates as $template) {
			$name = $template->get('emt_name');
			$options[$name] = $name;
		}

		$settings = Globalvars::get_instance();
		foreach ($used_by as $setting) {
			$stored = (string)$settings->get_setting($setting, true, true);
			if ($stored !== '' && !isset($options[$stored])) {
				$options[$stored] = $stored . ' (not a template name)';
			}
		}
		return $options;
	}
}
