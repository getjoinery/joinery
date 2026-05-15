<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_seo_page_edit_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$spm_id = $get_vars['spm_id'] ?? null;
	if (isset($post_vars['edit_primary_key_value']) && $post_vars['edit_primary_key_value']) {
		$spm = new SeoPageMetadata((int)$post_vars['edit_primary_key_value'], TRUE);
	} elseif ($spm_id) {
		$spm = new SeoPageMetadata((int)$spm_id, TRUE);
	} else {
		$spm = new SeoPageMetadata(NULL);
	}

	$error = null;

	if ($post_vars) {
		if (isset($post_vars['btn_delete']) && $spm->key) {
			$spm->soft_delete();
			return LogicResult::redirect('/admin/admin_seo_pages?notice=' . urlencode('SEO row soft-deleted.'));
		}

		try {
			if (!$spm->key) {
				$path = SeoPageMetadata::canonicalize_path($post_vars['spm_path'] ?? '');
				if ($path === '/' && trim($post_vars['spm_path'] ?? '') !== '/') {
					throw new SystemDisplayableError('Path is required.');
				}
				$spm->set('spm_path', $path);
			}

			foreach (array('spm_title', 'spm_meta_description', 'spm_og_title', 'spm_og_description', 'spm_preview_image_url', 'spm_og_type') as $field) {
				$val = isset($post_vars[$field]) ? trim($post_vars[$field]) : '';
				$spm->set($field, $val === '' ? null : $val);
			}
			$spm->set('spm_noindex', !empty($post_vars['spm_noindex']));

			$spm->save();
			return LogicResult::redirect('/admin/admin_seo_page_edit?spm_id=' . $spm->key . '&notice=' . urlencode('Saved.'));
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}
	}

	// Build $options as the public view would emit, so inference placeholders match render-time output.
	$entity_options = array();
	$entity_edit_link = null;
	if ($spm->key && $spm->get('spm_entity_type') && $spm->get('spm_entity_id')) {
		$type = $spm->get('spm_entity_type');
		$id   = (int)$spm->get('spm_entity_id');
		$entity_info = SeoPageMetadata::ENTITY_CLASSES[$type] ?? null;
		if ($entity_info && file_exists(PathHelper::getIncludePath($entity_info['file']))) {
			require_once(PathHelper::getIncludePath($entity_info['file']));
			$class = $entity_info['class'];
			if (class_exists($class)) {
				try {
					$entity = new $class($id, TRUE);
					$title_field = $class::$prefix . '_title';
					$name_field  = $class::$prefix . '_name';
					$desc_field  = $class::$prefix . '_short_description';
					$body_field  = $class::$prefix . '_body';

					$specs = $class::$field_specifications;
					if (isset($specs[$title_field])) $entity_options['title'] = $entity->get($title_field);
					elseif (isset($specs[$name_field])) $entity_options['title'] = $entity->get($name_field);
					if (isset($specs[$desc_field])) $entity_options['meta_description'] = $entity->get($desc_field);
					if (isset($specs[$body_field])) $entity_options['entity_body_html'] = $entity->get($body_field);
					$entity_options['entity_type'] = $type;
					$entity_options['og_type'] = SeoPageMetadata::infer_og_type('', array('entity_type'=>$type));

					$admin_edit_map = array(
						'post'         => '/admin/admin_post_edit?pst_post_id=',
						'event'        => '/admin/admin_event_edit?evt_event_id=',
						'product'      => '/admin/admin_product_edit?pro_product_id=',
						'page'         => '/admin/admin_page_edit?pag_page_id=',
						'location'     => '/admin/admin_location_edit?loc_location_id=',
						'video'        => '/admin/admin_video_edit?vid_video_id=',
						'mailing_list' => '/admin/admin_list_edit?mlt_mailing_list_id=',
					);
					if (isset($admin_edit_map[$type])) {
						$entity_edit_link = $admin_edit_map[$type] . $id;
					}
				} catch (\Throwable $e) {
					// entity missing or broken — leave placeholders to fall through to static inference
				}
			}
		}
	}

	$inferred = SeoPageMetadata::infer_for_request($spm->get('spm_path') ?: '/', $entity_options);
	$settings = Globalvars::get_instance();
	$site_name = $settings->get_setting('site_name');
	$site_description = $settings->get_setting('site_description');
	$site_preview = $settings->get_setting('preview_image');

	$resolved_title = $entity_options['title'] ?? $inferred['title'] ?? $site_name;
	$resolved_title = SeoPageMetadata::apply_title_format($resolved_title, $site_name);

	$placeholders = array(
		'spm_title'             => 'Defaults to: ' . ($resolved_title ?: '(unset)'),
		'spm_meta_description'  => 'Defaults to: ' . ($entity_options['meta_description'] ?? $inferred['meta_description'] ?? $site_description ?? '(unset)'),
		'spm_og_title'          => 'Defaults to: matches title above',
		'spm_og_description'    => 'Defaults to: matches meta description above',
		'spm_preview_image_url' => 'Defaults to: ' . ($inferred['preview_image'] ?? $site_preview ?? '(none)'),
		'spm_og_type'           => 'Defaults to: ' . ($entity_options['og_type'] ?? $inferred['og_type'] ?? 'website'),
	);

	return LogicResult::render(array(
		'session'           => $session,
		'spm'               => $spm,
		'placeholders'      => $placeholders,
		'entity_edit_link'  => $entity_edit_link,
		'notice'            => $get_vars['notice'] ?? null,
		'error'             => $error,
	));
}
?>
